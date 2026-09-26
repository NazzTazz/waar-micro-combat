use crate::addressed_random::{AddressedRandom, VERSION as ADDRESSED_PROTOCOL};
use crate::consequence_sampler::{ConsequenceSampler, VERSION as SAMPLING_PROTOCOL};
use crate::{
    CombatRng, Micro, UnitType, FIXED_SCALE, NUMERIC_MODEL_VERSION, STOCHASTIC_ENGINE_VERSION,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, HashSet};
#[cfg(feature = "b1-profile")]
use std::sync::atomic::{AtomicU64, Ordering};
#[cfg(feature = "b1-profile")]
use std::time::Instant;

#[cfg(feature = "b1-profile")]
static B1_WAVES: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static B1_REALLOCATION_WAVES: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static B1_MAX_COHORTS_PER_TYPE: AtomicU64 = AtomicU64::new(0);

const REQUEST_SCHEMA: &str = "waar-combat-request/2";
const RULESET_SCHEMA: &str = "waar-cohort-ruleset/2";
const MODEL_VERSION: &str = "waar-cohort-v2";
const SNAPSHOT_SCHEMA: &str = "waar-combat-snapshot/2";
const ACCURACY_VERSION: &str = "waar-accuracy-uniform-v1";
const CONSEQUENCE_VERSION: &str = "wounded-capture-then-compress/2";
const PROBABILISTIC_VERSION: &str = "wounded-capture-then-compress/3";
const ADDRESSED_POLICY: &str = "wounded-capture-then-compress/4";
fn historical_stochastic() -> String {
    STOCHASTIC_ENGINE_VERSION.into()
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct ArmyIdentities {
    attacker: String,
    defender: String,
}

fn non_null_identities<'de, D: serde::Deserializer<'de>>(
    deserializer: D,
) -> Result<Option<ArmyIdentities>, D::Error> {
    ArmyIdentities::deserialize(deserializer).map(Some)
}

fn validate_random(
    version: &str,
    ids: &Option<ArmyIdentities>,
    policy: Option<&ConsequenceSettings>,
) -> Result<(), String> {
    match (version, ids) {
        (STOCHASTIC_ENGINE_VERSION, None) => {}
        (ADDRESSED_PROTOCOL, Some(ids))
            if matches!(ids.attacker.as_str(), "A" | "B")
                && matches!(ids.defender.as_str(), "A" | "B")
                && ids.attacker != ids.defender => {}
        _ => return Err("unsupported stochastic protocol or invalid armyIdentities".into()),
    }
    if let Some(settings) = policy {
        if (settings.policy_version == ADDRESSED_POLICY) != (version == ADDRESSED_PROTOCOL) {
            return Err("incompatible consequence and stochastic protocols".into());
        }
    }
    Ok(())
}

fn sampling_protocol(version: &str) -> &'static str {
    match version {
        ADDRESSED_POLICY => ADDRESSED_PROTOCOL,
        PROBABILISTIC_VERSION => SAMPLING_PROTOCOL,
        _ => "floor/1",
    }
}
fn historical_policy() -> String {
    CONSEQUENCE_VERSION.into()
}

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
    #[serde(default, skip_serializing_if = "Option::is_none")]
    wound_damage_threshold: Option<Micro>,
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
    #[serde(default = "historical_policy")]
    policy_version: String,
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
    #[serde(default = "historical_stochastic")]
    stochastic_engine_version: String,
    #[serde(default, deserialize_with = "non_null_identities")]
    army_identities: Option<ArmyIdentities>,
}

impl Request {
    fn identity(&self, role: &str) -> &str {
        match (&self.army_identities, role) {
            (Some(ids), "attacker") => &ids.attacker,
            (Some(ids), _) => &ids.defender,
            (None, "attacker") => "attacker",
            (None, _) => "defender",
        }
    }
    fn addressed(&self, round: u32, role: &str) -> Option<AddressedRandom> {
        self.army_identities
            .as_ref()
            .map(|_| AddressedRandom::new(self.seed, self.identity(role), round))
    }
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
    fn wound_threshold(&self) -> Micro {
        self.wound_damage_threshold.unwrap_or(Micro::ZERO)
    }

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
        if self.wound_threshold().units() < 0 || self.wound_threshold().units() > FIXED_SCALE {
            return Err("invalid wound damage threshold".into());
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
    fn wounded(&self, i: usize, prepared: &PreparedSide, threshold: Micro) -> u32 {
        self.cohorts[i]
            .iter()
            .filter(|c| is_wounded(c.structure, prepared.units[i].structure, threshold))
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

fn event_accuracy(
    request: &Request,
    round: u32,
    role: &str,
    t: UnitType,
    base: Micro,
    spread: Micro,
) -> (Micro, Value) {
    if let Some(random) = request.addressed(round, role) {
        let lower = (base.units() - spread.units()).max(0);
        let upper = (base.units() + spread.units()).min(FIXED_SCALE);
        let value = Micro::from_units(random.integer(lower, upper, type_name(t), "accuracy"));
        (
            value,
            json!({"sampled":true,"lower":Micro::from_units(lower),"upper":Micro::from_units(upper),"value":value,"substream":hex_hash(random.domain(type_name(t), "accuracy").as_bytes())}),
        )
    } else {
        accuracy(request.seed, round, role, t, base, spread)
    }
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
    addressed: Option<&AddressedRandom>,
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
        let mut wave = 0;
        while pending > 0 && target.living() > 0 {
            #[cfg(feature = "b1-profile")]
            {
                B1_WAVES.fetch_add(1, Ordering::Relaxed);
                if wave > 0 {
                    B1_REALLOCATION_WAVES.fetch_add(1, Ordering::Relaxed);
                }
            }
            let allocations = allocate(pending, acting, &target, rules, rng, addressed, wave);
            let mut reallocated = 0u32;
            for target_type in UnitType::ALL {
                let j = target_type.index();
                let allocated = allocations[j];
                if allocated == 0 {
                    continue;
                }
                let probability = accuracies[i].units() as f64 / FIXED_SCALE as f64;
                let sampled = match addressed {
                    Some(random) => random.binomial(
                        allocated,
                        probability,
                        type_name(acting),
                        &format!("hit/{wave}/{}", type_name(target_type)),
                    ),
                    None => rng.binomial(allocated, probability),
                };
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
                    apply_impacts(
                        &mut target,
                        j,
                        applied,
                        damage,
                        rng,
                        addressed,
                        acting,
                        wave,
                    )?
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
            wave += 1;
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
    addressed: Option<&AddressedRandom>,
    wave: u32,
) -> [u32; 4] {
    let mut result = [0; 4];
    let living: Vec<_> = UnitType::ALL
        .into_iter()
        .filter(|t| target.living_type(t.index()) > 0)
        .collect();
    let mut remaining = attempts;
    if let Some(random) = addressed {
        let weights: Vec<_> = living
            .iter()
            .map(|t| (target.living_type(t.index()), rules.weight(acting, *t)))
            .collect();
        for (pos, t) in living.iter().enumerate() {
            let value = if pos + 1 == living.len() {
                remaining
            } else {
                random.binomial(
                    remaining,
                    target_probability(&weights[pos..]),
                    type_name(acting),
                    &format!("target/{wave}/{}", type_name(*t)),
                )
            };
            result[t.index()] = value;
            remaining -= value;
        }
        return result;
    }
    // Keep the historical arithmetic and random consumption for old replays.
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

// Remaining living targets in canonical order. Recompute the denominator to
// avoid cancellation, and scale preferences before population multiplication.
fn target_probability(weights: &[(u32, f64)]) -> f64 {
    let scale = weights.iter().map(|(_, w)| *w).fold(0.0, f64::max);
    let total: f64 = weights.iter().map(|(n, w)| *n as f64 * (*w / scale)).sum();
    (weights[0].0 as f64 * (weights[0].1 / scale)) / total
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
    addressed: Option<&AddressedRandom>,
    acting: UnitType,
    wave: u32,
) -> Result<i64, String> {
    let mut cohorts = army.cohorts[i].clone();
    #[cfg(feature = "b1-profile")]
    B1_MAX_COHORTS_PER_TYPE.fetch_max(cohorts.len() as u64, Ordering::Relaxed);
    if addressed.is_some() {
        cohorts.sort_by_key(|c| c.structure.units());
    }
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
            match addressed {
                Some(random) => random.binomial(
                    remaining_hits,
                    c.count as f64 / remaining_units as f64,
                    type_name(acting),
                    &format!(
                        "impact/{wave}/{}/{}",
                        type_name(UnitType::ALL[i]),
                        c.structure.units()
                    ),
                ),
                None => rng.binomial(remaining_hits, c.count as f64 / remaining_units as f64),
            }
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
    #[cfg(feature = "b1-profile")]
    B1_MAX_COHORTS_PER_TYPE.fetch_max(army.cohorts[i].len() as u64, Ordering::Relaxed);
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
fn is_wounded(remaining: Micro, maximum: Micro, threshold: Micro) -> bool {
    remaining.units() > 0
        && (maximum.units() - remaining.units()) as i128 * FIXED_SCALE as i128
            > threshold.units() as i128 * maximum.units() as i128
}

fn army_value(army: &Army, prepared: &PreparedSide, threshold: Micro) -> Value {
    let mut healthy = Map::new();
    let mut wounded = Map::new();
    let mut dead = Map::new();
    for t in UnitType::ALL {
        let i = t.index();
        let w = army.wounded(i, prepared, threshold);
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
                event_accuracy(
                    request,
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
                event_accuracy(
                    request,
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
            request.addressed(round, "attacker").as_ref(),
        )?;
        let da = resolve_action(
            &d_start,
            &a_start,
            dp,
            &request.ruleset,
            &mut rng,
            &dv,
            true,
            request.addressed(round, "defender").as_ref(),
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
    let mut value = json!({"schemaVersion":SNAPSHOT_SCHEMA,"rulesetVersion":request.ruleset.version,"seed":request.seed,"traceLevel":request.trace_level,"stochasticEngineVersion":request.stochastic_engine_version,"accuracySamplerVersion":if request.army_identities.is_some(){ADDRESSED_PROTOCOL}else{ACCURACY_VERSION},"numericModelVersion":NUMERIC_MODEL_VERSION,"prepared":{"attacker":prepared_value(ap),"defender":prepared_value(dp)}});
    if let Some(ids) = &request.army_identities {
        value["armyIdentities"] = json!(ids);
    }
    value
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
                    let (v, tr) = event_accuracy(
                        request,
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
                    let (v, tr) = event_accuracy(
                        request,
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
                request.addressed(round, "attacker").as_ref(),
            )?;
            let da = resolve_action(
                &d_start,
                &a_start,
                dp,
                &request.ruleset,
                &mut rng,
                &dv,
                true,
                request.addressed(round, "defender").as_ref(),
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
    let threshold = request.ruleset.wound_threshold();
    let result = json!({"schemaVersion":"waar-combat-result/2","modelVersion":MODEL_VERSION,"winner":winner,"reason":reason,"decision":decision,"rulesetVersion":request.ruleset.version,"replayHash":replay_hash,"snapshot":snapshot,"ruleset":request.ruleset,"initialArmies":{"attacker":counts_value(a.initial),"defender":counts_value(d.initial)},"attacker":army_value(&a,ap,threshold),"defender":army_value(&d,dp,threshold),"rounds":rounds});
    Ok((result, a, d, winner, replay_hash))
}

// Widen before multiplying: valid populations can occupy the full u32 range.
fn percentage_floor(count: u32, percent: u32) -> u32 {
    (u64::from(count) * u64::from(percent) / 100) as u32
}

fn validate_consequences(settings: &ConsequenceSettings) -> Result<(), String> {
    if settings.policy_version != CONSEQUENCE_VERSION
        && settings.policy_version != PROBABILISTIC_VERSION
        && settings.policy_version != ADDRESSED_POLICY
    {
        return Err("unsupported consequence policy".into());
    }
    if settings.compression_percent > 100 || settings.capture_percent > 50 {
        return Err("compression must be 0..100 and capture 0..50 percent".into());
    }
    Ok(())
}

fn consequence_side(
    army: &Army,
    prepared: &PreparedSide,
    defeated: bool,
    settings: &ConsequenceSettings,
    seed: i64,
    side: &str,
    threshold: Micro,
) -> Value {
    let mut types = Map::new();
    let mut initial_cost = 0u64;
    let mut lost = 0u64;
    for t in UnitType::ALL {
        let i = t.index();
        let initial = army.initial[i];
        let dead = army.dead[i];
        let wounded = army.wounded(i, prepared, threshold);
        let healthy = initial - dead - wounded;
        let [h_out, w_out, d_out, p_out, selected] =
            projected_counts(army, prepared, i, defeated, settings, seed, side, threshold);
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
// Single projection path for detailed reports and the fast batch.
fn projected_counts(
    army: &Army,
    prepared: &PreparedSide,
    i: usize,
    defeated: bool,
    settings: &ConsequenceSettings,
    seed: i64,
    side: &str,
    threshold: Micro,
) -> [u32; 5] {
    project_type(
        army.initial[i],
        army.dead[i],
        army.wounded(i, prepared, threshold),
        defeated && prepared.units[i].capturable,
        settings,
        seed,
        side,
        type_name(UnitType::ALL[i]),
    )
}
fn project_type(
    initial: u32,
    dead: u32,
    wounded: u32,
    eligible: bool,
    settings: &ConsequenceSettings,
    seed: i64,
    side: &str,
    unit: &str,
) -> [u32; 5] {
    let draw = |n, percent, stage| {
        if settings.policy_version == CONSEQUENCE_VERSION {
            percentage_floor(n, percent)
        } else if settings.policy_version == ADDRESSED_POLICY {
            AddressedRandom::new(seed, side, 0).binomial(
                n,
                percent as f64 / 100.0,
                unit,
                &format!("consequence/{stage}"),
            )
        } else {
            ConsequenceSampler::new(seed, side, unit, stage).binomial(n, percent)
        }
    };
    let selected = if eligible {
        draw(wounded, settings.capture_percent, "capture")
    } else {
        0
    };
    let d = draw(dead, settings.compression_percent, "dead");
    let w = draw(wounded - selected, settings.compression_percent, "wounded");
    let p = draw(selected, settings.compression_percent, "prisoners");
    [initial - d - w - p, w, d, p, selected]
}
fn consequences(
    a: &Army,
    d: &Army,
    ap: &PreparedSide,
    dp: &PreparedSide,
    winner: Option<&str>,
    hash: &str,
    seed: i64,
    settings: &ConsequenceSettings,
    threshold: Micro,
    request: &Request,
) -> Result<Value, String> {
    validate_consequences(settings)?;
    let mut output = json!({"schemaVersion":"waar-combat-consequences/1","policyVersion":settings.policy_version,"rawResult":hash,"compressionPercent":settings.compression_percent,"capturePercent":settings.capture_percent,"attacker":consequence_side(a,ap,winner==Some("defender"),settings,seed,request.identity("attacker"),threshold),"defender":consequence_side(d,dp,winner==Some("attacker"),settings,seed,request.identity("defender"),threshold)});
    if settings.policy_version != CONSEQUENCE_VERSION {
        output["samplingProtocol"] = json!(sampling_protocol(&settings.policy_version));
    }
    Ok(output)
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
    validate_random(
        &request.stochastic_engine_version,
        &request.army_identities,
        request.consequences.as_ref(),
    )?;
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
        out["consequences"] = consequences(
            &a,
            &d,
            &ap,
            &dp,
            winner,
            &hash,
            request.seed,
            settings,
            request.ruleset.wound_threshold(),
            request,
        )?;
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
    #[serde(default, deserialize_with = "non_null_identities")]
    army_identities: Option<ArmyIdentities>,
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
    #[serde(default = "historical_stochastic")]
    stochastic_engine_version: String,
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
fn resolve_batch_typed(
    batch: BatchRequest,
    expected_schema: &str,
    maximum_total_iterations: u32,
) -> Result<Value, String> {
    #[cfg(feature = "b1-profile")]
    let b1_started = Instant::now();
    #[cfg(feature = "b1-profile")]
    crate::addressed_random::b1_reset();
    #[cfg(feature = "b1-profile")]
    {
        B1_WAVES.store(0, Ordering::Relaxed);
        B1_REALLOCATION_WAVES.store(0, Ordering::Relaxed);
        B1_MAX_COHORTS_PER_TYPE.store(0, Ordering::Relaxed);
    }
    #[cfg(feature = "b1-profile")]
    let (mut b1_prepare, mut b1_resolve, mut b1_raw, mut b1_project, mut b1_rounds) =
        (0u128, 0u128, 0u128, 0u128, 0u64);
    let total_iterations = batch
        .total_iterations
        .unwrap_or(batch.start_iteration.saturating_add(batch.iterations));
    let end_iteration = batch.start_iteration.checked_add(batch.iterations);
    if batch.schema_version != expected_schema
        || batch.base_seed < 0
        || batch.base_seed > 2_147_483_647
        || batch.iterations == 0
        || batch.iterations > 100
        || total_iterations == 0
        || total_iterations > maximum_total_iterations
        || end_iteration.is_none_or(|end| end > total_iterations)
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
        #[cfg(feature = "b1-profile")]
        let b1_stage = Instant::now();
        if scenario.id.trim().is_empty()
            || !scenario_ids.insert(scenario.id.clone())
            || scenario.seed_key.is_some_and(|key| key < 0 || key > 2_147)
        {
            return Err(
                "scenario ids must be unique and seedKey must be between 0 and 2147".into(),
            );
        }
        validate_random(
            &batch.stochastic_engine_version,
            &scenario.army_identities,
            batch.consequences.as_ref(),
        )?;
        validate_side(&scenario.attacker)?;
        validate_side(&scenario.defender)?;
        let ap = prepare(&batch.ruleset, scenario.attacker.modifiers.clone())?;
        let dp = prepare(&batch.ruleset, scenario.defender.modifiers.clone())?;
        #[cfg(feature = "b1-profile")]
        {
            b1_prepare += b1_stage.elapsed().as_nanos();
        }
        let mut wins = [0u64; 3];
        let mut round_sum = 0u64;
        let mut ad = [0u64; 4];
        let mut dd = [0u64; 4];
        let mut aw = [0u64; 4];
        let mut dw = [0u64; 4];
        let mut projected_a = [[0u64; 4]; 4];
        let mut projected_d = [[0u64; 4]; 4];
        let wound_threshold = batch.ruleset.wound_threshold();
        for offset in 0..batch.iterations {
            let request = Request {
                schema_version: REQUEST_SCHEMA.into(),
                ruleset: batch.ruleset.clone(),
                attacker: scenario.attacker.clone(),
                defender: scenario.defender.clone(),
                seed: scenario_seed(batch.base_seed, scenario, batch.start_iteration + offset),
                trace_level: "none".into(),
                consequences: None,
                stochastic_engine_version: batch.stochastic_engine_version.clone(),
                army_identities: scenario.army_identities.clone(),
            };
            #[cfg(feature = "b1-profile")]
            let b1_stage = Instant::now();
            let (a, d, winner, rounds) = resolve_fast(&request, &ap, &dp)?;
            #[cfg(feature = "b1-profile")]
            {
                b1_resolve += b1_stage.elapsed().as_nanos();
                b1_rounds += rounds as u64;
            }
            #[cfg(feature = "b1-profile")]
            let b1_stage = Instant::now();
            round_sum += rounds as u64;
            match winner {
                Some("attacker") => wins[0] += 1,
                Some("defender") => wins[1] += 1,
                _ => wins[2] += 1,
            }
            for i in 0..4 {
                ad[i] += a.dead[i] as u64;
                dd[i] += d.dead[i] as u64;
                aw[i] += a.wounded(i, &ap, wound_threshold) as u64;
                dw[i] += d.wounded(i, &dp, wound_threshold) as u64;
            }
            #[cfg(feature = "b1-profile")]
            {
                b1_raw += b1_stage.elapsed().as_nanos();
            }
            #[cfg(feature = "b1-profile")]
            let b1_stage = Instant::now();
            if let Some(settings) = &batch.consequences {
                for i in 0..4 {
                    let ai = projected_counts(
                        &a,
                        &ap,
                        i,
                        winner == Some("defender"),
                        settings,
                        request.seed,
                        request.identity("attacker"),
                        wound_threshold,
                    );
                    let di = projected_counts(
                        &d,
                        &dp,
                        i,
                        winner == Some("attacker"),
                        settings,
                        request.seed,
                        request.identity("defender"),
                        wound_threshold,
                    );
                    for k in 0..4 {
                        projected_a[i][k] += ai[k] as u64;
                        projected_d[i][k] += di[k] as u64;
                    }
                }
            }
            #[cfg(feature = "b1-profile")]
            {
                b1_project += b1_stage.elapsed().as_nanos();
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
        let mut row = json!({"id":scenario.id,"result":{"samples":batch.iterations,"attackerWins":wins[0],"defenderWins":wins[1],"draws":wins[2],"roundSum":round_sum,"attackerInitialByType":attacker_initial,"defenderInitialByType":defender_initial,"attackerRawDeathsByType":ad,"defenderRawDeathsByType":dd,"attackerRawWoundedByType":aw,"defenderRawWoundedByType":dw,"attackerProjectedByType":if batch.consequences.is_some(){json!(projected_a)}else{Value::Null},"defenderProjectedByType":if batch.consequences.is_some(){json!(projected_d)}else{Value::Null}}});
        if let Some(ids) = &scenario.army_identities {
            row["armyIdentities"] = json!(ids);
        }
        scenarios.push(row);
    }
    let mut output = json!({"schemaVersion":"waar-combat-batch-result/2","modelVersion":MODEL_VERSION,"unitOrder":["soldier","spearman","archer","knight"],"projectedCategoryOrder":["healthy","wounded","dead","prisoners"],"iterations":batch.iterations,"startIteration":batch.start_iteration,"iterationRange":{"start":batch.start_iteration,"endExclusive":end_iteration.unwrap(),"total":total_iterations,"complete":batch.start_iteration==0&&batch.iterations==total_iterations},"totalCombats":batch.iterations as usize*batch.scenarios.len(),"scenarios":scenarios});
    if batch.stochastic_engine_version == ADDRESSED_PROTOCOL {
        output["stochasticEngineVersion"] = json!(ADDRESSED_PROTOCOL);
    }
    if let Some(threshold) = batch.ruleset.wound_damage_threshold {
        output["classificationProvenance"] = json!({"woundDamageThreshold":threshold});
    }
    if let Some(settings) = &batch.consequences {
        output["consequenceProvenance"] = json!({"policyVersion":settings.policy_version,"samplingProtocol":sampling_protocol(&settings.policy_version),"compressionPercent":settings.compression_percent,"capturePercent":settings.capture_percent});
    }
    #[cfg(feature = "b1-profile")]
    {
        let total = b1_started.elapsed().as_nanos();
        let measured = b1_prepare + b1_resolve + b1_raw + b1_project;
        eprintln!(
            "B1_PROFILE {}",
            json!({
                "totalNs": total,
                "prepareNs": b1_prepare,
                "resolveFastNs": b1_resolve,
                "rawAggregationNs": b1_raw,
                "projectionNs": b1_project,
                "otherNs": total.saturating_sub(measured),
                "rounds": b1_rounds,
                "combats": batch.iterations as usize * batch.scenarios.len(),
                "addressedRandom": crate::addressed_random::b1_counts(),
                "rngSamples": crate::addressed_random::b1_samples(),
                "allocationWaves": B1_WAVES.load(Ordering::Relaxed),
                "reallocationWaves": B1_REALLOCATION_WAVES.load(Ordering::Relaxed),
                "maxCohortsPerType": B1_MAX_COHORTS_PER_TYPE.load(Ordering::Relaxed),
            })
        );
    }
    Ok(output)
}
pub fn resolve_batch_json(input: &str) -> String {
    let result = (|| {
        let request: BatchRequest =
            serde_json::from_str(input).map_err(|e| format!("invalid combat batch JSON: {e}"))?;
        resolve_batch_typed(request, "waar-combat-batch-request/2", 100)
    })();
    match result {
        Ok(v) => serde_json::to_string(&v).unwrap(),
        Err(error) => serde_json::to_string(&json!({"error":error})).unwrap(),
    }
}

pub fn resolve_campaign_batch_json(input: &str) -> String {
    let result = (|| {
        let request: BatchRequest =
            serde_json::from_str(input).map_err(|e| format!("invalid campaign batch JSON: {e}"))?;
        resolve_batch_typed(
            request,
            "waar-combat-campaign-batch-request/1",
            2_147_483_647,
        )
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
    fn targeting_probabilities_remain_finite_without_erasing_the_tail() {
        let weights = [(1, 1e16), (1, 2.9), (1, 0.01)];
        assert!((target_probability(&weights[1..]) - 2.9 / 2.91).abs() < 1e-15);
        for weights in [
            weights.to_vec(),
            vec![(1, 1e16), (1, 0.01), (1, 0.01)],
            vec![(1, f64::MAX), (3, f64::MAX), (2, f64::MAX)],
            vec![
                (1, f64::from_bits(1)),
                (3, f64::from_bits(2)),
                (2, f64::from_bits(3)),
            ],
            vec![
                (u32::MAX, f64::MAX),
                (1, f64::from_bits(1)),
                (2, f64::from_bits(1)),
            ],
        ] {
            for pos in 0..weights.len() {
                let p = target_probability(&weights[pos..]);
                assert!(p.is_finite() && (0.0..=1.0).contains(&p));
            }
        }
        assert_eq!(target_probability(&[(3, f64::MAX), (2, f64::MAX)]), 0.6);
        assert_eq!(
            target_probability(&[(1, f64::from_bits(1)), (1, f64::from_bits(1))]),
            0.5
        );
    }

    #[test]
    fn percentages_preserve_large_populations() {
        assert_eq!(percentage_floor(50_000_000, 100), 50_000_000);
        assert_eq!(percentage_floor(u32::MAX, 100), u32::MAX);
        assert_eq!(percentage_floor(u32::MAX, 10), 429_496_729);
        assert_eq!(percentage_floor(u32::MAX, 0), 0);
    }
    #[test]
    fn wound_threshold_boundaries_are_strict_and_fixed_point() {
        let maximum = Micro::from_decimal_str("250").unwrap();
        let threshold = Micro::from_decimal_str("0.2").unwrap();
        assert!(!is_wounded(
            Micro::from_decimal_str("250").unwrap(),
            maximum,
            threshold
        ));
        assert!(!is_wounded(
            Micro::from_decimal_str("200").unwrap(),
            maximum,
            threshold
        ));
        assert!(is_wounded(
            Micro::from_decimal_str("199.999999").unwrap(),
            maximum,
            threshold
        ));
        assert!(is_wounded(
            Micro::from_decimal_str("249.999999").unwrap(),
            maximum,
            Micro::ZERO
        ));
        assert!(!is_wounded(
            Micro::from_decimal_str("249.999999").unwrap(),
            maximum,
            Micro::from_decimal_str("1").unwrap()
        ));
        assert!(!is_wounded(Micro::ZERO, maximum, Micro::ZERO));
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

    #[test]
    fn addressed_cohorts_have_stable_order_after_merge() {
        let make = |rows: &[(i64, u32)]| {
            let mut army = Army {
                cohorts: std::array::from_fn(|_| Vec::new()),
                dead: [0; 4],
                initial: [0, 0, 5, 0],
            };
            army.replace(
                2,
                rows.iter()
                    .map(|(s, n)| Cohort {
                        structure: Micro::from_units(s * FIXED_SCALE),
                        count: *n,
                    })
                    .collect(),
                0,
            );
            army
        };
        let mut a = make(&[(20, 2), (10, 3)]);
        let mut b = make(&[(10, 1), (20, 1), (10, 2), (20, 1)]);
        let random = AddressedRandom::new(42, "B", 1);
        for army in [&mut a, &mut b] {
            apply_impacts(
                army,
                2,
                4,
                Micro::from_units(5 * FIXED_SCALE),
                &mut CombatRng::new(42),
                Some(&random),
                UnitType::Spearman,
                0,
            )
            .unwrap();
        }
        let rows = |army: &Army| {
            army.cohorts[2]
                .iter()
                .map(|c| (c.structure.units(), c.count))
                .collect::<Vec<_>>()
        };
        assert_eq!(rows(&a), rows(&b));
        assert_eq!(a.dead, b.dead);
    }

    #[cfg(feature = "b1-profile")]
    #[test]
    fn b1_fragmented_impacts_probe() {
        let random = AddressedRandom::new(42, "B", 1);
        let fixture = |fragmented: bool| {
            let mut army = Army {
                cohorts: std::array::from_fn(|_| Vec::new()),
                dead: [0; 4],
                initial: [0, 0, 5, 0],
            };
            let rows: &[(i64, u32)] = if fragmented {
                &[(20, 2), (10, 3)]
            } else {
                &[(20, 5)]
            };
            army.replace(
                2,
                rows.iter()
                    .map(|(s, n)| Cohort {
                        structure: Micro::from_units(s * FIXED_SCALE),
                        count: *n,
                    })
                    .collect(),
                0,
            );
            army
        };
        for fragmented in [false, true] {
            let started = Instant::now();
            let mut checksum = 0i64;
            for _ in 0..1000 {
                let mut army = fixture(fragmented);
                let damage = apply_impacts(
                    &mut army,
                    2,
                    4,
                    Micro::from_units(5 * FIXED_SCALE),
                    &mut CombatRng::new(42),
                    Some(&random),
                    UnitType::Spearman,
                    0,
                )
                .unwrap();
                checksum += damage + army.dead[2] as i64;
            }
            eprintln!(
                "B1_FRAGMENTATION {}",
                json!({"fixture":"addressed_cohorts_have_stable_order_after_merge","fragmented":fragmented,"iterations":1000,"elapsedNs":started.elapsed().as_nanos(),"checksum":checksum})
            );
            assert!(checksum > 0);
        }
    }
}
