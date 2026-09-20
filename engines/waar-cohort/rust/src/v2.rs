use crate::{
    CombatRng, Micro, UnitType, FIXED_SCALE, NUMERIC_MODEL_VERSION, STOCHASTIC_ENGINE_VERSION,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, HashSet};

const REQUEST_SCHEMA: &str = "waar-combat-request/2";
const RULESET_SCHEMA: &str = "waar-cohort-ruleset/2";
const MODEL_VERSION: &str = "waar-cohort-v2";
const SNAPSHOT_SCHEMA: &str = "waar-combat-snapshot/2";
const ACCURACY_VERSION: &str = "waar-accuracy-uniform-v1";
const CONSEQUENCE_VERSION: &str = "wounded-capture-then-compress/2";

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct UnitDef {
    #[serde(rename = "type")]
    unit_type: UnitType,
    attack: Micro,
    structure: Micro,
    cost: u32,
    base_accuracy: Micro,
    #[serde(default)]
    accuracy_spread: Micro,
    #[serde(default = "one_u32")]
    strikes_per_attack: u32,
    #[serde(default = "one_micro")]
    defending_efficiency: Micro,
    #[serde(default)]
    capturable: bool,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct EngagementRuleV2 {
    attack_factor: Micro,
    #[serde(default)]
    is_provisional: bool,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct TargetRow {
    weights: BTreeMap<String, f64>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Surrender {
    enabled: bool,
    dead_ratio: Micro,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct TieBreak {
    criterion: String,
    equality: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Ruleset {
    schema_version: String,
    model_version: String,
    version: String,
    units: Vec<UnitDef>,
    targeting_mode: String,
    targeting: BTreeMap<String, TargetRow>,
    engagements: BTreeMap<String, BTreeMap<String, EngagementRuleV2>>,
    max_rounds: u32,
    surrender: Surrender,
    tie_break: TieBreak,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Modifier {
    source: String,
    id: String,
    label: String,
    unit_type: UnitType,
    parameter: String,
    operation: String,
    value: Value,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct SideInput {
    units: BTreeMap<String, u32>,
    #[serde(default)]
    modifiers: Vec<Modifier>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct ConsequenceSettings {
    compression_percent: u32,
    capture_percent: u32,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Request {
    schema_version: String,
    ruleset: Ruleset,
    attacker: SideInput,
    defender: SideInput,
    seed: i64,
    #[serde(default = "full_trace")]
    trace_level: String,
    consequences: Option<ConsequenceSettings>,
}

#[derive(Clone, Debug)]
struct PreparedUnit {
    base: UnitDef,
    attack: Micro,
    structure: Micro,
    cost: u32,
    base_accuracy: Micro,
    accuracy_spread: Micro,
    strikes: u32,
    defense: Micro,
    capturable: bool,
    effects: Vec<Modifier>,
}

#[derive(Clone, Debug)]
struct PreparedSide {
    units: [PreparedUnit; 4],
    modifiers: Vec<Modifier>,
}

#[derive(Clone, Debug)]
struct Cohort {
    structure: Micro,
    count: u32,
}

#[derive(Clone, Debug)]
struct Army {
    cohorts: [Vec<Cohort>; 4],
    dead: [u32; 4],
    initial: [u32; 4],
}

#[derive(Clone, Debug, Default)]
struct Cell {
    source_count: u32,
    strikes: u32,
    allocated: u64,
    consumed: u64,
    reallocated: u64,
    sampled_hits: u64,
    applied_hits: u64,
    attack_per_strike: Micro,
    accuracy: Micro,
    factor: Micro,
    defense: Micro,
    damage_per_hit: Micro,
    emitted: i64,
    absorbed: i64,
    overkill: i64,
}

#[derive(Clone, Debug)]
struct Action {
    target: Army,
    attempts: u64,
    hits: u64,
    deaths: u32,
    matrix: [[Cell; 4]; 4],
}

fn one_u32() -> u32 {
    1
}
fn one_micro() -> Micro {
    Micro::from_units(FIXED_SCALE)
}
fn full_trace() -> String {
    "full".into()
}
fn type_name(t: UnitType) -> &'static str {
    match t {
        UnitType::Soldier => "soldier",
        UnitType::Spearman => "spearman",
        UnitType::Archer => "archer",
        UnitType::Knight => "knight",
    }
}
impl Ruleset {
    fn validate(&self) -> Result<(), String> {
        if self.schema_version != RULESET_SCHEMA
            || self.model_version != MODEL_VERSION
            || self.targeting_mode != "proportional"
        {
            return Err("unsupported cohort ruleset schema, model or targeting mode".into());
        }
        if self.version.trim().is_empty() || self.max_rounds == 0 || self.max_rounds > 100 {
            return Err("invalid ruleset metadata".into());
        }
        if self.surrender.dead_ratio.units() < 0 || self.surrender.dead_ratio.units() > FIXED_SCALE
        {
            return Err("invalid surrender threshold".into());
        }
        if !matches!(self.tie_break.criterion.as_str(), "economic" | "structure")
            || !matches!(self.tie_break.equality.as_str(), "defender" | "draw")
        {
            return Err("invalid tie-break".into());
        }
        if self.units.len() != 4 {
            return Err("four unit definitions are required".into());
        }
        let mut seen = HashSet::new();
        for unit in &self.units {
            if !seen.insert(unit.unit_type.index()) {
                return Err("duplicate unit definition".into());
            }
            if unit.attack.units() < 0
                || unit.attack.units() > 1000 * FIXED_SCALE
                || unit.structure.units() <= 0
                || unit.structure.units() > 1000 * FIXED_SCALE
                || unit.cost == 0
                || unit.cost > 400400
                || unit.base_accuracy.units() < 0
                || unit.base_accuracy.units() > FIXED_SCALE
                || unit.accuracy_spread.units() < 0
                || unit.accuracy_spread.units() > FIXED_SCALE
                || unit.strikes_per_attack == 0
                || unit.strikes_per_attack > 32
                || unit.defending_efficiency.units() < 0
                || unit.defending_efficiency.units() > 10 * FIXED_SCALE
            {
                return Err("unit value outside supported range".into());
            }
        }
        for acting in UnitType::ALL {
            for target in UnitType::ALL {
                let weight = self
                    .targeting
                    .get(type_name(acting))
                    .and_then(|r| r.weights.get(type_name(target)))
                    .ok_or("missing target weight")?;
                if !weight.is_finite() || *weight <= 0.0 {
                    return Err("target weights must be finite and positive".into());
                }
                let factor = self
                    .engagements
                    .get(type_name(acting))
                    .and_then(|r| r.get(type_name(target)))
                    .ok_or("missing engagement")?
                    .attack_factor
                    .units();
                if factor < 0 || factor > 100 * FIXED_SCALE {
                    return Err("attack factor outside supported range".into());
                }
            }
        }
        Ok(())
    }
    fn unit(&self, t: UnitType) -> &UnitDef {
        self.units.iter().find(|u| u.unit_type == t).unwrap()
    }
    fn factor(&self, a: UnitType, t: UnitType) -> Micro {
        self.engagements[type_name(a)][type_name(t)].attack_factor
    }
    fn weight(&self, a: UnitType, t: UnitType) -> f64 {
        self.targeting[type_name(a)].weights[type_name(t)]
    }
}

fn prepare(rules: &Ruleset, mut modifiers: Vec<Modifier>) -> Result<PreparedSide, String> {
    let mut seen = HashSet::new();
    for m in &modifiers {
        if m.source.trim().is_empty() || m.id.trim().is_empty() || m.label.trim().is_empty() {
            return Err("modifier source, id and label are required".into());
        }
        if !seen.insert(format!("{}\0{}", m.source, m.id)) {
            return Err(format!("duplicate modifier: {}/{}", m.source, m.id));
        }
        let numeric = matches!(
            m.parameter.as_str(),
            "attack"
                | "structure"
                | "baseAccuracy"
                | "accuracySpread"
                | "defendingEfficiency"
                | "cost"
                | "strikesPerAttack"
        );
        if m.source == "weather"
            && (!matches!(m.parameter.as_str(), "attack" | "baseAccuracy")
                || m.operation != "multiply")
        {
            return Err("weather may only multiply attack or base accuracy".into());
        }
        if numeric {
            if m.operation != "multiply" || m.value.as_str().is_none() {
                return Err("numeric modifiers require a decimal string multiplier".into());
            }
            let factor = Micro::from_decimal_str(m.value.as_str().unwrap())?;
            if factor.units() < 0 || factor.units() > 10 * FIXED_SCALE {
                return Err("modifier factor outside 0..10".into());
            }
        } else if m.parameter == "capturable" {
            if m.operation != "set" || !m.value.is_boolean() {
                return Err("capturable modifiers require a boolean set".into());
            }
        } else {
            return Err(format!("unknown modifier parameter: {}", m.parameter));
        }
    }
    modifiers.sort_by_key(|m| {
        (
            m.source == "weather",
            m.source.clone(),
            m.id.clone(),
            type_name(m.unit_type).to_string(),
            m.parameter.clone(),
        )
    });
    let mut units = Vec::new();
    for t in UnitType::ALL {
        let base = rules.unit(t).clone();
        let effects: Vec<_> = modifiers
            .iter()
            .filter(|m| m.unit_type == t)
            .cloned()
            .collect();
        let decimal = |name: &str, value: Micro| -> Result<Micro, String> {
            let mut values = vec![value.units()];
            for m in effects.iter().filter(|m| m.parameter == name) {
                values.push(Micro::from_decimal_str(m.value.as_str().unwrap())?.units());
            }
            Ok(Micro::from_units(multiply_many(&values)?))
        };
        let integer = |name: &str, value: u32| -> Result<u32, String> {
            let result = decimal(name, Micro::from_units(value as i64 * FIXED_SCALE))?.units();
            if result % FIXED_SCALE != 0 {
                return Err(format!("prepared {name} must remain an integer"));
            }
            u32::try_from(result / FIXED_SCALE)
                .map_err(|_| format!("prepared {name} outside integer range"))
        };
        let mut values = effects
            .iter()
            .filter(|m| m.parameter == "capturable")
            .map(|m| m.value.as_bool().unwrap())
            .collect::<Vec<_>>();
        values.sort();
        values.dedup();
        if values.len() > 1 {
            return Err(format!(
                "conflicting capturable modifiers for {}",
                type_name(t)
            ));
        }
        let unit = PreparedUnit {
            attack: decimal("attack", base.attack)?,
            structure: decimal("structure", base.structure)?,
            cost: integer("cost", base.cost)?,
            base_accuracy: decimal("baseAccuracy", base.base_accuracy)?,
            accuracy_spread: decimal("accuracySpread", base.accuracy_spread)?,
            strikes: integer("strikesPerAttack", base.strikes_per_attack)?,
            defense: decimal("defendingEfficiency", base.defending_efficiency)?,
            capturable: values.first().copied().unwrap_or(base.capturable),
            base,
            effects,
        };
        if unit.attack.units() < 0
            || unit.attack.units() > 1000 * FIXED_SCALE
            || unit.structure.units() <= 0
            || unit.structure.units() > 1000 * FIXED_SCALE
            || unit.cost == 0
            || unit.cost > 400400
            || unit.base_accuracy.units() < 0
            || unit.base_accuracy.units() > FIXED_SCALE
            || unit.accuracy_spread.units() < 0
            || unit.accuracy_spread.units() > FIXED_SCALE
            || unit.strikes == 0
            || unit.strikes > 32
            || unit.defense.units() < 0
            || unit.defense.units() > 10 * FIXED_SCALE
        {
            return Err(format!(
                "prepared value outside supported range for {}",
                type_name(t)
            ));
        }
        units.push(unit);
    }
    Ok(PreparedSide {
        units: units.try_into().unwrap(),
        modifiers,
    })
}

fn multiply_many(values: &[i64]) -> Result<i64, String> {
    let mut digits = vec![1u8];
    for &value in values {
        if value < 0 {
            return Err("modifier products cannot be negative".into());
        }
        digits = multiply_digits(&digits, value as u64);
    }
    let discard = 6 * (values.len() - 1);
    if discard > 0 {
        while digits.len() <= discard {
            digits.insert(0, 0);
        }
        let round = digits[discard - 1] >= 5;
        digits.drain(0..discard);
        if round {
            let mut carry = 1;
            for d in &mut digits {
                let n = *d + carry;
                *d = n % 10;
                carry = n / 10;
                if carry == 0 {
                    break;
                }
            }
            if carry > 0 {
                digits.push(carry);
            }
        }
    }
    let mut result: i64 = 0;
    for d in digits.iter().rev() {
        result = result
            .checked_mul(10)
            .and_then(|x| x.checked_add(*d as i64))
            .ok_or("prepared fixed-point overflow")?;
    }
    Ok(result)
}
fn multiply_digits(left: &[u8], mut right: u64) -> Vec<u8> {
    if right == 0 {
        return vec![0];
    }
    let mut right_digits = Vec::new();
    while right > 0 {
        right_digits.push((right % 10) as u8);
        right /= 10;
    }
    let mut out = vec![0u32; left.len() + right_digits.len()];
    for (i, a) in left.iter().enumerate() {
        for (j, b) in right_digits.iter().enumerate() {
            out[i + j] += *a as u32 * *b as u32;
        }
    }
    for i in 0..out.len() - 1 {
        let carry = out[i] / 10;
        out[i] %= 10;
        out[i + 1] += carry;
    }
    while out.len() > 1 && out.last() == Some(&0) {
        out.pop();
    }
    out.into_iter().map(|x| x as u8).collect()
}

impl Army {
    fn from_input(input: &SideInput, prepared: &PreparedSide) -> Self {
        let initial =
            std::array::from_fn(|i| *input.units.get(type_name(UnitType::ALL[i])).unwrap_or(&0));
        let cohorts = std::array::from_fn(|i| {
            if initial[i] > 0 {
                vec![Cohort {
                    structure: prepared.units[i].structure,
                    count: initial[i],
                }]
            } else {
                Vec::new()
            }
        });
        Self {
            cohorts,
            dead: [0; 4],
            initial,
        }
    }
    fn living_type(&self, i: usize) -> u32 {
        self.cohorts[i].iter().map(|c| c.count).sum()
    }
    fn living(&self) -> u32 {
        (0..4).map(|i| self.living_type(i)).sum()
    }
    fn dead_total(&self) -> u32 {
        self.dead.iter().sum()
    }
    fn death_ratio(&self) -> f64 {
        let initial: u32 = self.initial.iter().sum();
        if initial == 0 {
            0.0
        } else {
            self.dead_total() as f64 / initial as f64
        }
    }
    fn reaches_death_ratio(&self, threshold: Micro) -> bool {
        let initial: u32 = self.initial.iter().sum();
        initial > 0
            && self.dead_total() as i128 * FIXED_SCALE as i128
                >= threshold.units() as i128 * initial as i128
    }
    fn wounded(&self, i: usize, prepared: &PreparedSide) -> u32 {
        self.cohorts[i]
            .iter()
            .filter(|c| c.structure < prepared.units[i].structure)
            .map(|c| c.count)
            .sum()
    }
    fn remaining_value(&self, prepared: &PreparedSide) -> u64 {
        (0..4)
            .map(|i| self.living_type(i) as u64 * prepared.units[i].cost as u64)
            .sum()
    }
    fn total_structure(&self) -> i128 {
        self.cohorts
            .iter()
            .flatten()
            .map(|c| c.structure.units() as i128 * c.count as i128)
            .sum()
    }
    fn initial_structure(&self, prepared: &PreparedSide) -> i128 {
        (0..4)
            .map(|i| self.initial[i] as i128 * prepared.units[i].structure.units() as i128)
            .sum()
    }
    fn replace(&mut self, i: usize, cohorts: Vec<Cohort>, deaths: u32) {
        let mut merged = Vec::<Cohort>::new();
        for c in cohorts {
            if let Some(existing) = merged.iter_mut().find(|row| row.structure == c.structure) {
                existing.count += c.count;
                continue;
            }
            merged.push(c);
        }
        self.cohorts[i] = merged;
        self.dead[i] += deaths;
    }
}

fn accuracy(
    seed: i64,
    round: u32,
    role: &str,
    t: UnitType,
    base: Micro,
    spread: Micro,
) -> (Micro, Value) {
    let lower = (base.units() - spread.units()).max(0);
    let upper = (base.units() + spread.units()).min(FIXED_SCALE);
    let identity = format!(
        "{ACCURACY_VERSION}\0{seed}\0{round}\0{role}\0{}",
        type_name(t)
    );
    let digest = Sha256::digest(identity.as_bytes());
    let mut state =
        (u32::from_be_bytes([digest[0], digest[1], digest[2], digest[3]]) & 0x7fff_ffff) as u64;
    let value = if lower == upper {
        lower
    } else {
        let range = (upper - lower + 1) as u64;
        let bucket = 2_147_483_648u64 / range;
        let limit = bucket * range;
        loop {
            state = (1_103_515_245u64 * state + 12_345) % 2_147_483_648;
            if state < limit {
                break lower + (state / bucket) as i64;
            }
        }
    };
    let hash = digest
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect::<String>();
    let value = Micro::from_units(value);
    (
        value,
        json!({"sampled":true,"lower":Micro::from_units(lower),"upper":Micro::from_units(upper),"value":value,"substream":hash}),
    )
}

fn micro_div(value: Micro, divisor: u32) -> Micro {
    Micro::from_units((value.units() + divisor as i64 / 2) / divisor as i64)
}

fn empty_matrix(
    prepared: &PreparedSide,
    rules: &Ruleset,
    accuracies: &[Micro; 4],
    defending: bool,
    source: &Army,
) -> Result<[[Cell; 4]; 4], String> {
    let mut matrix: [[Cell; 4]; 4] =
        std::array::from_fn(|_| std::array::from_fn(|_| Cell::default()));
    for a in UnitType::ALL {
        for t in UnitType::ALL {
            let unit = &prepared.units[a.index()];
            let per = micro_div(unit.attack, unit.strikes);
            let mut damage = per.multiply(rules.factor(a, t))?;
            if defending {
                damage = damage.multiply(unit.defense)?;
            }
            matrix[a.index()][t.index()] = Cell {
                source_count: source.living_type(a.index()),
                strikes: unit.strikes,
                attack_per_strike: per,
                accuracy: accuracies[a.index()],
                factor: rules.factor(a, t),
                defense: if defending { unit.defense } else { one_micro() },
                damage_per_hit: damage,
                ..Cell::default()
            };
        }
    }
    Ok(matrix)
}

fn resolve_action(
    source: &Army,
    target_start: &Army,
    prepared: &PreparedSide,
    rules: &Ruleset,
    rng: &mut CombatRng,
    accuracies: &[Micro; 4],
    defending: bool,
) -> Result<Action, String> {
    let mut target = target_start.clone();
    let mut matrix = empty_matrix(prepared, rules, accuracies, defending, source)?;
    let before = target.dead_total();
    let mut attempts = 0u64;
    let mut hits = 0u64;
    for acting in UnitType::ALL {
        let i = acting.index();
        let count = source.living_type(i);
        let strikes = prepared.units[i].strikes;
        let mut pending = count.checked_mul(strikes).ok_or("strike count overflow")?;
        attempts += pending as u64;
        while pending > 0 && target.living() > 0 {
            let allocations = allocate(pending, acting, &target, rules, rng);
            let mut reallocated = 0u32;
            for target_type in UnitType::ALL {
                let j = target_type.index();
                let allocated = allocations[j];
                if allocated == 0 {
                    continue;
                }
                let sampled =
                    rng.binomial(allocated, accuracies[i].units() as f64 / FIXED_SCALE as f64);
                let damage = matrix[i][j].damage_per_hit;
                let needed = impacts_needed(&target, j, damage)?;
                let (mut consumed, mut applied) = (allocated, sampled);
                if let Some(needed) = needed {
                    if sampled as u64 >= needed {
                        let needed = needed as u32;
                        consumed = ((allocated as u64 * needed as u64 + sampled as u64 - 1)
                            / sampled as u64)
                            .max(1)
                            .min(allocated as u64) as u32;
                        applied = needed;
                        reallocated += allocated - consumed;
                    }
                }
                let absorbed = if applied > 0 {
                    apply_impacts(&mut target, j, applied, damage, rng)?
                } else {
                    0
                };
                let emitted = damage
                    .units()
                    .checked_mul(applied as i64)
                    .ok_or("damage trace overflow")?;
                let cell = &mut matrix[i][j];
                cell.allocated += allocated as u64;
                cell.consumed += consumed as u64;
                cell.reallocated += (allocated - consumed) as u64;
                cell.sampled_hits += sampled as u64;
                cell.applied_hits += applied as u64;
                cell.emitted += emitted;
                cell.absorbed += absorbed;
                cell.overkill += (emitted - absorbed).max(0);
                hits += applied as u64;
            }
            pending = reallocated;
        }
    }
    Ok(Action {
        deaths: target.dead_total() - before,
        target,
        attempts,
        hits,
        matrix,
    })
}

fn allocate(
    attempts: u32,
    acting: UnitType,
    target: &Army,
    rules: &Ruleset,
    rng: &mut CombatRng,
) -> [u32; 4] {
    let mut result = [0; 4];
    let living: Vec<_> = UnitType::ALL
        .into_iter()
        .filter(|t| target.living_type(t.index()) > 0)
        .collect();
    let mut remaining = attempts;
    let mut total: f64 = living
        .iter()
        .map(|t| target.living_type(t.index()) as f64 * rules.weight(acting, *t))
        .sum();
    for (pos, t) in living.iter().enumerate() {
        let weight = target.living_type(t.index()) as f64 * rules.weight(acting, *t);
        let value = if pos + 1 == living.len() {
            remaining
        } else {
            rng.binomial(remaining, weight / total)
        };
        result[t.index()] = value;
        remaining -= value;
        total -= weight;
    }
    result
}
fn impacts_needed(army: &Army, i: usize, damage: Micro) -> Result<Option<u64>, String> {
    if damage.units() <= 0 {
        return Ok(None);
    }
    let mut needed = 0u64;
    for c in &army.cohorts[i] {
        needed = needed
            .checked_add(
                c.structure
                    .ceil_ratio(damage)?
                    .checked_mul(c.count as u64)
                    .ok_or("impact count overflow")?,
            )
            .ok_or("impact count overflow")?;
    }
    Ok(Some(needed))
}
fn apply_impacts(
    army: &mut Army,
    i: usize,
    impacts: u32,
    damage: Micro,
    rng: &mut CombatRng,
) -> Result<i64, String> {
    let cohorts = army.cohorts[i].clone();
    let before: i64 = cohorts
        .iter()
        .map(|c| c.structure.units() * c.count as i64)
        .sum();
    let mut remaining_hits = impacts;
    let mut remaining_units: u32 = cohorts.iter().map(|c| c.count).sum();
    let mut allocations = Vec::new();
    for (index, c) in cohorts.iter().enumerate() {
        let value = if index + 1 == cohorts.len() {
            remaining_hits
        } else {
            rng.binomial(remaining_hits, c.count as f64 / remaining_units as f64)
        };
        allocations.push(value);
        remaining_hits -= value;
        remaining_units -= c.count;
    }
    let mut updated = Vec::new();
    let mut deaths = 0;
    for (c, allocated) in cohorts.into_iter().zip(allocations) {
        let per = allocated / c.count;
        let rest = allocated % c.count;
        append_damage(&mut updated, &mut deaths, &c, c.count - rest, per, damage)?;
        append_damage(&mut updated, &mut deaths, &c, rest, per + 1, damage)?;
    }
    army.replace(i, updated, deaths);
    let after: i64 = army.cohorts[i]
        .iter()
        .map(|c| c.structure.units() * c.count as i64)
        .sum();
    Ok(before - after)
}
fn append_damage(
    out: &mut Vec<Cohort>,
    deaths: &mut u32,
    source: &Cohort,
    count: u32,
    hits: u32,
    damage: Micro,
) -> Result<(), String> {
    if count == 0 {
        return Ok(());
    }
    let structure = source.structure.subtract_repeated(hits, damage)?;
    if structure.units() <= 0 {
        *deaths += count
    } else {
        out.push(Cohort { structure, count })
    }
    Ok(())
}

fn prepared_value(side: &PreparedSide) -> Value {
    json!({"units":side.units.iter().map(|u|json!({"type":type_name(u.base.unit_type),"attack":u.attack,"structure":u.structure,"cost":u.cost,"baseAccuracy":u.base_accuracy,"accuracySpread":u.accuracy_spread,"strikesPerAttack":u.strikes,"defendingEfficiency":u.defense,"capturable":u.capturable,"base":u.base,"effects":u.effects})).collect::<Vec<_>>(),"modifiers":side.modifiers})
}
fn counts_value(initial: [u32; 4]) -> Value {
    let mut map = Map::new();
    for t in UnitType::ALL {
        map.insert(type_name(t).into(), json!(initial[t.index()]));
    }
    Value::Object(map)
}
fn army_value(army: &Army, prepared: &PreparedSide) -> Value {
    let mut healthy = Map::new();
    let mut wounded = Map::new();
    let mut dead = Map::new();
    for t in UnitType::ALL {
        let i = t.index();
        let w = army.wounded(i, prepared);
        wounded.insert(type_name(t).into(), json!(w));
        healthy.insert(type_name(t).into(), json!(army.living_type(i) - w));
        dead.insert(type_name(t).into(), json!(army.dead[i]));
    }
    let mut cohorts = Vec::new();
    for t in UnitType::ALL {
        for c in &army.cohorts[t.index()] {
            cohorts.push(
                json!({"type":type_name(t),"remainingStructure":c.structure,"count":c.count}),
            );
        }
    }
    cohorts.sort_by(|a, b| {
        let names = a["type"].as_str().unwrap().cmp(b["type"].as_str().unwrap());
        if names.is_eq() {
            let av = Micro::from_decimal_str(a["remainingStructure"].as_str().unwrap())
                .unwrap()
                .units();
            let bv = Micro::from_decimal_str(b["remainingStructure"].as_str().unwrap())
                .unwrap()
                .units();
            av.cmp(&bv)
        } else {
            names
        }
    });
    json!({"healthy":healthy,"wounded":wounded,"dead":dead,"cohorts":cohorts})
}
fn matrix_value(matrix: &[[Cell; 4]; 4]) -> Value {
    let mut rows = Map::new();
    for a in UnitType::ALL {
        let mut targets = Map::new();
        for t in UnitType::ALL {
            let c = &matrix[a.index()][t.index()];
            targets.insert(type_name(t).into(),json!({"sourceCount":c.source_count,"strikesPerAttack":c.strikes,"allocatedAttempts":c.allocated,"consumedAttempts":c.consumed,"reallocatedAttempts":c.reallocated,"sampledHits":c.sampled_hits,"appliedHits":c.applied_hits,"attackPerStrike":c.attack_per_strike,"accuracy":c.accuracy,"attackFactor":c.factor,"defendingEfficiency":c.defense,"damagePerHit":c.damage_per_hit,"damageEmitted":Micro::from_units(c.emitted),"damageAbsorbed":Micro::from_units(c.absorbed),"overkill":Micro::from_units(c.overkill)}));
        }
        rows.insert(type_name(a).into(), Value::Object(targets));
    }
    Value::Object(rows)
}
fn action_value(action: &Action) -> Value {
    json!({"attempts":action.attempts,"hits":action.hits,"deaths":action.deaths,"matrix":matrix_value(&action.matrix)})
}
fn ratio_string(value: f64) -> String {
    Micro::from_units((value * FIXED_SCALE as f64).round() as i64).format()
}

fn tie_break_winner(
    a: &Army,
    d: &Army,
    ap: &PreparedSide,
    dp: &PreparedSide,
    rules: &Ruleset,
) -> Option<&'static str> {
    let comparison = if rules.tie_break.criterion == "economic" {
        a.remaining_value(ap).cmp(&d.remaining_value(dp))
    } else {
        (a.total_structure() * d.initial_structure(dp))
            .cmp(&(d.total_structure() * a.initial_structure(ap)))
    };
    match comparison {
        std::cmp::Ordering::Greater => Some("attacker"),
        std::cmp::Ordering::Less => Some("defender"),
        std::cmp::Ordering::Equal => {
            if rules.tie_break.equality == "defender" {
                Some("defender")
            } else {
                None
            }
        }
    }
}

fn resolve_fast(
    request: &Request,
    ap: &PreparedSide,
    dp: &PreparedSide,
) -> Result<(Army, Army, Option<&'static str>, u32), String> {
    let mut a = Army::from_input(&request.attacker, ap);
    let mut d = Army::from_input(&request.defender, dp);
    if a.initial.iter().sum::<u32>() == 0 && d.initial.iter().sum::<u32>() == 0 {
        return Err("both armies cannot be empty".into());
    }
    if a.living() == 0 {
        return Ok((a, d, Some("defender"), 0));
    }
    if d.living() == 0 {
        return Ok((a, d, Some("attacker"), 0));
    }
    let mut rng = CombatRng::new(request.seed);
    for round in 1..=request.ruleset.max_rounds {
        let a_start = a.clone();
        let d_start = d.clone();
        let mut av = [Micro::ZERO; 4];
        let mut dv = [Micro::ZERO; 4];
        for t in UnitType::ALL {
            let i = t.index();
            av[i] = if a_start.living_type(i) > 0 {
                accuracy(
                    request.seed,
                    round,
                    "attacker",
                    t,
                    ap.units[i].base_accuracy,
                    ap.units[i].accuracy_spread,
                )
                .0
            } else {
                ap.units[i].base_accuracy
            };
            dv[i] = if d_start.living_type(i) > 0 {
                accuracy(
                    request.seed,
                    round,
                    "defender",
                    t,
                    dp.units[i].base_accuracy,
                    dp.units[i].accuracy_spread,
                )
                .0
            } else {
                dp.units[i].base_accuracy
            };
        }
        let aa = resolve_action(
            &a_start,
            &d_start,
            ap,
            &request.ruleset,
            &mut rng,
            &av,
            false,
        )?;
        let da = resolve_action(
            &d_start,
            &a_start,
            dp,
            &request.ruleset,
            &mut rng,
            &dv,
            true,
        )?;
        d = aa.target;
        a = da.target;
        let ae = a.living() == 0;
        let de = d.living() == 0;
        if ae || de {
            let winner = if ae && de {
                tie_break_winner(&a, &d, ap, dp, &request.ruleset)
            } else if ae {
                Some("defender")
            } else {
                Some("attacker")
            };
            return Ok((a, d, winner, round));
        }
        if request.ruleset.surrender.enabled {
            let asur = a.reaches_death_ratio(request.ruleset.surrender.dead_ratio);
            let dsur = d.reaches_death_ratio(request.ruleset.surrender.dead_ratio);
            if asur || dsur {
                let winner = if asur && dsur {
                    tie_break_winner(&a, &d, ap, dp, &request.ruleset)
                } else if asur {
                    Some("defender")
                } else {
                    Some("attacker")
                };
                return Ok((a, d, winner, round));
            }
        }
        if round == request.ruleset.max_rounds {
            let winner = tie_break_winner(&a, &d, ap, dp, &request.ruleset);
            return Ok((a, d, winner, round));
        }
    }
    unreachable!()
}

fn tie_break(
    a: &Army,
    d: &Army,
    ap: &PreparedSide,
    dp: &PreparedSide,
    rules: &Ruleset,
    trigger: &str,
) -> (Option<&'static str>, Value) {
    let (comparison, values) = if rules.tie_break.criterion == "economic" {
        let av = a.remaining_value(ap);
        let dv = d.remaining_value(dp);
        (av.cmp(&dv), json!({"attacker":av,"defender":dv}))
    } else {
        let an = a.total_structure();
        let ad = a.initial_structure(ap);
        let dn = d.total_structure();
        let dd = d.initial_structure(dp);
        (
            (an * dd).cmp(&(dn * ad)),
            json!({"attacker":{"remainingUnits":an as i64,"initialUnits":ad as i64},"defender":{"remainingUnits":dn as i64,"initialUnits":dd as i64}}),
        )
    };
    let cmp = match comparison {
        std::cmp::Ordering::Less => -1,
        std::cmp::Ordering::Equal => 0,
        std::cmp::Ordering::Greater => 1,
    };
    let winner = if cmp == 0 {
        if rules.tie_break.equality == "defender" {
            Some("defender")
        } else {
            None
        }
    } else if cmp > 0 {
        Some("attacker")
    } else {
        Some("defender")
    };
    (
        winner,
        json!({"trigger":trigger,"criterion":rules.tie_break.criterion,"values":values,"comparison":cmp,"equalityPolicy":rules.tie_break.equality}),
    )
}

fn snapshot_value(request: &Request, ap: &PreparedSide, dp: &PreparedSide) -> Value {
    json!({"schemaVersion":SNAPSHOT_SCHEMA,"rulesetVersion":request.ruleset.version,"seed":request.seed,"traceLevel":request.trace_level,"stochasticEngineVersion":STOCHASTIC_ENGINE_VERSION,"accuracySamplerVersion":ACCURACY_VERSION,"numericModelVersion":NUMERIC_MODEL_VERSION,"prepared":{"attacker":prepared_value(ap),"defender":prepared_value(dp)}})
}

fn resolve_core(
    request: &Request,
    ap: &PreparedSide,
    dp: &PreparedSide,
) -> Result<(Value, Army, Army, Option<&'static str>, String), String> {
    let mut a = Army::from_input(&request.attacker, ap);
    let mut d = Army::from_input(&request.defender, dp);
    if a.initial.iter().sum::<u32>() == 0 && d.initial.iter().sum::<u32>() == 0 {
        return Err("both armies cannot be empty".into());
    }
    let snapshot = snapshot_value(request, ap, dp);
    let replay = json!({"ruleset":request.ruleset,"snapshot":snapshot,"armies":{"attacker":counts_value(a.initial),"defender":counts_value(d.initial)}});
    let replay_hash = hex_hash(
        serde_json::to_string(&replay)
            .map_err(|e| e.to_string())?
            .as_bytes(),
    );
    let mut rounds = Vec::new();
    let mut winner = None;
    let mut reason = "initial_empty";
    let mut decision = json!({"criterion":"initial_empty"});
    if a.living() > 0 && d.living() > 0 {
        let mut rng = CombatRng::new(request.seed);
        for round in 1..=request.ruleset.max_rounds {
            let a_start = a.clone();
            let d_start = d.clone();
            let mut av = [Micro::ZERO; 4];
            let mut dv = [Micro::ZERO; 4];
            let mut at = Map::new();
            let mut dt = Map::new();
            for t in UnitType::ALL {
                let i = t.index();
                if a_start.living_type(i) > 0 {
                    let (v, tr) = accuracy(
                        request.seed,
                        round,
                        "attacker",
                        t,
                        ap.units[i].base_accuracy,
                        ap.units[i].accuracy_spread,
                    );
                    av[i] = v;
                    at.insert(type_name(t).into(), tr);
                } else {
                    av[i] = ap.units[i].base_accuracy;
                    at.insert(type_name(t).into(),json!({"sampled":false,"lower":null,"upper":null,"value":null,"substream":null}));
                }
                if d_start.living_type(i) > 0 {
                    let (v, tr) = accuracy(
                        request.seed,
                        round,
                        "defender",
                        t,
                        dp.units[i].base_accuracy,
                        dp.units[i].accuracy_spread,
                    );
                    dv[i] = v;
                    dt.insert(type_name(t).into(), tr);
                } else {
                    dv[i] = dp.units[i].base_accuracy;
                    dt.insert(type_name(t).into(),json!({"sampled":false,"lower":null,"upper":null,"value":null,"substream":null}));
                }
            }
            let aa = resolve_action(
                &a_start,
                &d_start,
                ap,
                &request.ruleset,
                &mut rng,
                &av,
                false,
            )?;
            let da = resolve_action(
                &d_start,
                &a_start,
                dp,
                &request.ruleset,
                &mut rng,
                &dv,
                true,
            )?;
            d = aa.target.clone();
            a = da.target.clone();
            let mut r = json!({"number":round,"accuracy":{"attacker":at,"defender":dt},"attackerDeathRatio":ratio_string(a.death_ratio()),"defenderDeathRatio":ratio_string(d.death_ratio())});
            if request.trace_level == "full" {
                r["attackerAction"] = action_value(&aa);
                r["defenderAction"] = action_value(&da);
            }
            rounds.push(r);
            let ae = a.living() == 0;
            let de = d.living() == 0;
            if ae || de {
                reason = "elimination";
                if ae && de {
                    (winner, decision) =
                        tie_break(&a, &d, ap, dp, &request.ruleset, "double_elimination");
                } else {
                    winner = if ae {
                        Some("defender")
                    } else {
                        Some("attacker")
                    };
                    decision = json!({"criterion":"elimination","attackerLiving":a.living(),"defenderLiving":d.living()});
                }
                break;
            }
            if request.ruleset.surrender.enabled {
                let asur = a.reaches_death_ratio(request.ruleset.surrender.dead_ratio);
                let dsur = d.reaches_death_ratio(request.ruleset.surrender.dead_ratio);
                if asur || dsur {
                    reason = "surrender";
                    if asur && dsur {
                        (winner, decision) =
                            tie_break(&a, &d, ap, dp, &request.ruleset, "double_surrender");
                    } else {
                        winner = if asur {
                            Some("defender")
                        } else {
                            Some("attacker")
                        };
                        decision = json!({"criterion":"surrender","threshold":request.ruleset.surrender.dead_ratio,"attackerDeadRatio":ratio_string(a.death_ratio()),"defenderDeadRatio":ratio_string(d.death_ratio())});
                    }
                    break;
                }
            }
            if round == request.ruleset.max_rounds {
                reason = "round_limit";
                (winner, decision) = tie_break(&a, &d, ap, dp, &request.ruleset, "round_limit");
            }
        }
    } else {
        winner = if a.living() > 0 {
            Some("attacker")
        } else {
            Some("defender")
        };
    }
    let result = json!({"schemaVersion":"waar-combat-result/2","modelVersion":MODEL_VERSION,"winner":winner,"reason":reason,"decision":decision,"rulesetVersion":request.ruleset.version,"replayHash":replay_hash,"snapshot":snapshot,"ruleset":request.ruleset,"initialArmies":{"attacker":counts_value(a.initial),"defender":counts_value(d.initial)},"attacker":army_value(&a,ap),"defender":army_value(&d,dp),"rounds":rounds});
    Ok((result, a, d, winner, replay_hash))
}

// Widen before multiplying: valid populations can occupy the full u32 range.
fn percentage_floor(count: u32, percent: u32) -> u32 {
    (u64::from(count) * u64::from(percent) / 100) as u32
}

fn validate_consequences(settings: &ConsequenceSettings) -> Result<(), String> {
    if settings.compression_percent > 100 || settings.capture_percent > 10 {
        return Err("compression must be 0..100 and capture 0..10 percent".into());
    }
    Ok(())
}

fn consequence_side(
    army: &Army,
    prepared: &PreparedSide,
    defeated: bool,
    settings: &ConsequenceSettings,
) -> Value {
    let mut types = Map::new();
    let mut initial_cost = 0u64;
    let mut lost = 0u64;
    for t in UnitType::ALL {
        let i = t.index();
        let initial = army.initial[i];
        let dead = army.dead[i];
        let wounded = army.wounded(i, prepared);
        let healthy = initial - dead - wounded;
        let selected = if defeated && prepared.units[i].capturable {
            percentage_floor(wounded, settings.capture_percent)
        } else {
            0
        };
        let free = wounded - selected;
        let d_out = percentage_floor(dead, settings.compression_percent);
        let w_out = percentage_floor(free, settings.compression_percent);
        let p_out = percentage_floor(selected, settings.compression_percent);
        let h_out = initial - d_out - w_out - p_out;
        let cost = prepared.units[i].cost;
        initial_cost += initial as u64 * cost as u64;
        lost += (d_out + w_out) as u64 * cost as u64;
        types.insert(type_name(t).into(),json!({"initial":initial,"raw":{"healthy":healthy,"wounded":wounded,"dead":dead},"projected":{"healthy":h_out,"wounded":w_out,"dead":d_out,"prisoners":p_out,"freeSurvivors":h_out+w_out},"capturable":prepared.units[i].capturable,"prisonersSelectedBeforeCompression":selected,"unitCost":cost}));
    }
    let percent = if initial_cost == 0 {
        "0".into()
    } else {
        Micro::from_units(
            ((lost as u128 * 100 * FIXED_SCALE as u128 + initial_cost as u128 / 2)
                / initial_cost as u128) as i64,
        )
        .format()
    };
    json!({"defeated":defeated,"types":types,"initialCost":initial_cost,"economicLoss":lost,"economicLossPercent":percent})
}
fn projected_counts(
    army: &Army,
    prepared: &PreparedSide,
    i: usize,
    defeated: bool,
    settings: &ConsequenceSettings,
) -> [u32; 4] {
    let initial = army.initial[i];
    let dead = army.dead[i];
    let wounded = army.wounded(i, prepared);
    let selected = if defeated && prepared.units[i].capturable {
        percentage_floor(wounded, settings.capture_percent)
    } else {
        0
    };
    let d = percentage_floor(dead, settings.compression_percent);
    let w = percentage_floor(wounded - selected, settings.compression_percent);
    let p = percentage_floor(selected, settings.compression_percent);
    [initial - d - w - p, w, d, p]
}
fn consequences(
    a: &Army,
    d: &Army,
    ap: &PreparedSide,
    dp: &PreparedSide,
    winner: Option<&str>,
    hash: &str,
    settings: &ConsequenceSettings,
) -> Result<Value, String> {
    validate_consequences(settings)?;
    Ok(
        json!({"schemaVersion":"waar-combat-consequences/1","policyVersion":CONSEQUENCE_VERSION,"rawResult":hash,"compressionPercent":settings.compression_percent,"capturePercent":settings.capture_percent,"attacker":consequence_side(a,ap,winner==Some("defender"),settings),"defender":consequence_side(d,dp,winner==Some("attacker"),settings)}),
    )
}

fn resolve_request(request: &Request) -> Result<Value, String> {
    if request.schema_version != REQUEST_SCHEMA
        || request.seed < 0
        || request.seed > 2_147_483_647
        || !matches!(request.trace_level.as_str(), "none" | "full")
    {
        return Err("unsupported request schema, seed or trace level".into());
    }
    request.ruleset.validate()?;
    if let Some(settings) = &request.consequences {
        validate_consequences(settings)?;
    }
    validate_side(&request.attacker)?;
    validate_side(&request.defender)?;
    let ap = prepare(&request.ruleset, request.attacker.modifiers.clone())?;
    let dp = prepare(&request.ruleset, request.defender.modifiers.clone())?;
    let (result, a, d, winner, hash) = resolve_core(request, &ap, &dp)?;
    let mut out = json!({"result":result});
    if let Some(settings) = &request.consequences {
        out["consequences"] = consequences(&a, &d, &ap, &dp, winner, &hash, settings)?;
    }
    Ok(out)
}
fn validate_side(side: &SideInput) -> Result<(), String> {
    for key in side.units.keys() {
        if !UnitType::ALL.into_iter().any(|t| type_name(t) == key) {
            return Err(format!("unknown unit count key: {key}"));
        }
    }
    Ok(())
}
fn hex_hash(bytes: &[u8]) -> String {
    Sha256::digest(bytes)
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect()
}

pub fn resolve_json(input: &str) -> String {
    let result = (|| {
        let request: Request =
            serde_json::from_str(input).map_err(|e| format!("invalid combat JSON: {e}"))?;
        resolve_request(&request)
    })();
    match result {
        Ok(v) => serde_json::to_string(&v).unwrap(),
        Err(error) => serde_json::to_string(&json!({"error":error})).unwrap(),
    }
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct BatchScenario {
    id: String,
    #[serde(default)]
    seed_key: Option<i64>,
    attacker: SideInput,
    defender: SideInput,
}
#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct BatchRequest {
    schema_version: String,
    ruleset: Ruleset,
    base_seed: i64,
    iterations: u32,
    #[serde(default)]
    start_iteration: u32,
    #[serde(default)]
    total_iterations: Option<u32>,
    scenarios: Vec<BatchScenario>,
    consequences: Option<ConsequenceSettings>,
}

fn scenario_seed(base: i64, scenario: &BatchScenario, index: u32) -> i64 {
    if let Some(key) = scenario.seed_key {
        (base + key * 1_000_003 + index as i64).rem_euclid(2_147_483_647)
    } else {
        let text = format!("{base}\0{}\0{index}", scenario.id);
        let h = Sha256::digest(text.as_bytes());
        (u32::from_be_bytes([h[0], h[1], h[2], h[3]]) & 0x7fff_ffff) as i64
    }
}
fn resolve_batch_typed(batch: BatchRequest) -> Result<Value, String> {
    let total_iterations = batch
        .total_iterations
        .unwrap_or(batch.start_iteration.saturating_add(batch.iterations));
    if batch.schema_version != "waar-combat-batch-request/2"
        || batch.base_seed < 0
        || batch.base_seed > 2_147_483_647
        || batch.iterations == 0
        || batch.iterations > 100
        || total_iterations == 0
        || total_iterations > 100
        || batch.start_iteration.saturating_add(batch.iterations) > total_iterations
        || batch.scenarios.is_empty()
    {
        return Err("invalid batch request".into());
    }
    batch.ruleset.validate()?;
    if let Some(settings) = &batch.consequences {
        validate_consequences(settings)?;
    }
    let mut scenarios = Vec::new();
    let mut scenario_ids = HashSet::new();
    for scenario in &batch.scenarios {
        if scenario.id.trim().is_empty()
            || !scenario_ids.insert(scenario.id.clone())
            || scenario.seed_key.is_some_and(|key| key < 0 || key > 2_147)
        {
            return Err(
                "scenario ids must be unique and seedKey must be between 0 and 2147".into(),
            );
        }
        validate_side(&scenario.attacker)?;
        validate_side(&scenario.defender)?;
        let ap = prepare(&batch.ruleset, scenario.attacker.modifiers.clone())?;
        let dp = prepare(&batch.ruleset, scenario.defender.modifiers.clone())?;
        let mut wins = [0u64; 3];
        let mut round_sum = 0u64;
        let mut ad = [0u64; 4];
        let mut dd = [0u64; 4];
        let mut aw = [0u64; 4];
        let mut dw = [0u64; 4];
        let mut projected_a = [[0u64; 4]; 4];
        let mut projected_d = [[0u64; 4]; 4];
        for offset in 0..batch.iterations {
            let request = Request {
                schema_version: REQUEST_SCHEMA.into(),
                ruleset: batch.ruleset.clone(),
                attacker: scenario.attacker.clone(),
                defender: scenario.defender.clone(),
                seed: scenario_seed(batch.base_seed, scenario, batch.start_iteration + offset),
                trace_level: "none".into(),
                consequences: None,
            };
            let (a, d, winner, rounds) = resolve_fast(&request, &ap, &dp)?;
            round_sum += rounds as u64;
            match winner {
                Some("attacker") => wins[0] += 1,
                Some("defender") => wins[1] += 1,
                _ => wins[2] += 1,
            }
            for i in 0..4 {
                ad[i] += a.dead[i] as u64;
                dd[i] += d.dead[i] as u64;
                aw[i] += a.wounded(i, &ap) as u64;
                dw[i] += d.wounded(i, &dp) as u64;
            }
            if let Some(settings) = &batch.consequences {
                for i in 0..4 {
                    let ai = projected_counts(&a, &ap, i, winner == Some("defender"), settings);
                    let di = projected_counts(&d, &dp, i, winner == Some("attacker"), settings);
                    for k in 0..4 {
                        projected_a[i][k] += ai[k] as u64;
                        projected_d[i][k] += di[k] as u64;
                    }
                }
            }
        }
        let attacker_initial: [u32; 4] = std::array::from_fn(|i| {
            *scenario
                .attacker
                .units
                .get(type_name(UnitType::ALL[i]))
                .unwrap_or(&0)
        });
        let defender_initial: [u32; 4] = std::array::from_fn(|i| {
            *scenario
                .defender
                .units
                .get(type_name(UnitType::ALL[i]))
                .unwrap_or(&0)
        });
        scenarios.push(json!({"id":scenario.id,"result":{"samples":batch.iterations,"attackerWins":wins[0],"defenderWins":wins[1],"draws":wins[2],"roundSum":round_sum,"attackerInitialByType":attacker_initial,"defenderInitialByType":defender_initial,"attackerRawDeathsByType":ad,"defenderRawDeathsByType":dd,"attackerRawWoundedByType":aw,"defenderRawWoundedByType":dw,"attackerProjectedByType":if batch.consequences.is_some(){json!(projected_a)}else{Value::Null},"defenderProjectedByType":if batch.consequences.is_some(){json!(projected_d)}else{Value::Null}}}));
    }
    Ok(
        json!({"schemaVersion":"waar-combat-batch-result/2","modelVersion":MODEL_VERSION,"unitOrder":["soldier","spearman","archer","knight"],"projectedCategoryOrder":["healthy","wounded","dead","prisoners"],"iterations":batch.iterations,"startIteration":batch.start_iteration,"iterationRange":{"start":batch.start_iteration,"endExclusive":batch.start_iteration+batch.iterations,"total":total_iterations,"complete":batch.start_iteration==0&&batch.iterations==total_iterations},"totalCombats":batch.iterations as usize*batch.scenarios.len(),"scenarios":scenarios}),
    )
}
pub fn resolve_batch_json(input: &str) -> String {
    let result = (|| {
        let request: BatchRequest =
            serde_json::from_str(input).map_err(|e| format!("invalid combat batch JSON: {e}"))?;
        resolve_batch_typed(request)
    })();
    match result {
        Ok(v) => serde_json::to_string(&v).unwrap(),
        Err(error) => serde_json::to_string(&json!({"error":error})).unwrap(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn percentages_preserve_large_populations() {
        assert_eq!(percentage_floor(50_000_000, 100), 50_000_000);
        assert_eq!(percentage_floor(u32::MAX, 100), u32::MAX);
        assert_eq!(percentage_floor(u32::MAX, 10), 429_496_729);
        assert_eq!(percentage_floor(u32::MAX, 0), 0);
    }
    #[test]
    fn accuracy_vectors() {
        let (v, _) = accuracy(
            42,
            1,
            "attacker",
            UnitType::Soldier,
            Micro::from_decimal_str("0.2").unwrap(),
            Micro::from_decimal_str("0.01").unwrap(),
        );
        assert_eq!(v.format(), "0.207745");
        let (v, _) = accuracy(
            42,
            1,
            "defender",
            UnitType::Archer,
            Micro::from_decimal_str("0.15").unwrap(),
            Micro::from_decimal_str("0.02").unwrap(),
        );
        assert_eq!(v.format(), "0.131025");
    }
    #[test]
    fn exact_product_rounds_once() {
        assert_eq!(multiply_many(&[150000, 1200000, 800000]).unwrap(), 144000);
        assert_eq!(multiply_many(&[150000, 800000, 1200000]).unwrap(), 144000);
    }
}
