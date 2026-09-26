//! Stable A/B event addresses and nested binomial partitions; specification §8.7.
use crate::consequence_sampler::ConsequenceSampler;
#[cfg(feature = "b1-profile")]
use std::sync::atomic::{AtomicU64, Ordering};
#[cfg(feature = "b1-profile")]
use std::sync::{Mutex, OnceLock};

pub const VERSION: &str = "sha256-binomial-tree/1";
const GRID: u64 = 1u64 << 52;
#[cfg(feature = "b1-profile")]
static HIT_CALLS: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static TARGET_CALLS: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static IMPACT_CALLS: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static CONSEQUENCE_CALLS: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static TREE_STEPS: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static INTEGER_CALLS: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static SAMPLE_COUNT: AtomicU64 = AtomicU64::new(0);
#[cfg(feature = "b1-profile")]
static SAMPLES: OnceLock<Mutex<Vec<serde_json::Value>>> = OnceLock::new();

#[cfg(feature = "b1-profile")]
pub fn b1_reset() {
    for counter in [
        &HIT_CALLS,
        &TARGET_CALLS,
        &IMPACT_CALLS,
        &CONSEQUENCE_CALLS,
        &TREE_STEPS,
        &INTEGER_CALLS,
    ] {
        counter.store(0, Ordering::Relaxed);
    }
    SAMPLE_COUNT.store(0, Ordering::Relaxed);
    SAMPLES
        .get_or_init(|| Mutex::new(Vec::new()))
        .lock()
        .unwrap()
        .clear();
}

#[cfg(feature = "b1-profile")]
pub fn b1_counts() -> serde_json::Value {
    serde_json::json!({
        "hitCalls": HIT_CALLS.load(Ordering::Relaxed),
        "targetCalls": TARGET_CALLS.load(Ordering::Relaxed),
        "impactCalls": IMPACT_CALLS.load(Ordering::Relaxed),
        "consequenceCalls": CONSEQUENCE_CALLS.load(Ordering::Relaxed),
        "treeSteps": TREE_STEPS.load(Ordering::Relaxed),
        "integerCalls": INTEGER_CALLS.load(Ordering::Relaxed),
    })
}

#[cfg(feature = "b1-profile")]
pub fn b1_samples() -> serde_json::Value {
    serde_json::json!(SAMPLES
        .get_or_init(|| Mutex::new(Vec::new()))
        .lock()
        .unwrap()
        .clone())
}

pub struct AddressedRandom {
    seed: i64,
    army: String,
    round: u32,
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn shared_vectors() {
        let vectors: serde_json::Value =
            serde_json::from_str(include_str!("../../tests/fixtures/addressed-vectors.json"))
                .unwrap();
        for v in vectors.as_array().unwrap() {
            let r = AddressedRandom::new(
                v["seed"].as_i64().unwrap(),
                v["army"].as_str().unwrap(),
                v["round"].as_u64().unwrap() as u32,
            );
            let unit = v["type"].as_str().unwrap();
            assert_eq!(
                r.binomial(
                    v["n"].as_u64().unwrap() as u32,
                    v["p"].as_f64().unwrap(),
                    unit,
                    v["usage"].as_str().unwrap()
                ) as u64,
                v["sample"].as_u64().unwrap(),
                "{v}"
            );
            assert_eq!(
                r.integer(12345, 987654, unit, "accuracy"),
                v["integer"].as_i64().unwrap(),
                "{v}"
            );
        }
    }
}

impl AddressedRandom {
    pub fn new(seed: i64, army: &str, round: u32) -> Self {
        Self {
            seed,
            army: army.into(),
            round,
        }
    }

    pub fn domain(&self, unit: &str, usage: &str) -> String {
        format!(
            "{VERSION}\0{}\0{}\0{}\0{unit}\0{usage}",
            self.seed, self.army, self.round
        )
    }

    pub fn binomial(&self, mut n: u32, p: f64, unit: &str, usage: &str) -> u32 {
        assert!(p.is_finite() && (0.0..=1.0).contains(&p));
        #[cfg(feature = "b1-profile")]
        {
            let counter = if usage.starts_with("hit/") {
                &HIT_CALLS
            } else if usage.starts_with("target/") {
                &TARGET_CALLS
            } else if usage.starts_with("impact/") {
                &IMPACT_CALLS
            } else {
                &CONSEQUENCE_CALLS
            };
            counter.fetch_add(1, Ordering::Relaxed);
            if SAMPLE_COUNT.fetch_add(1, Ordering::Relaxed) < 16 {
                SAMPLES
                    .get_or_init(|| Mutex::new(Vec::new()))
                    .lock()
                    .unwrap()
                    .push(serde_json::json!({
                        "seed": self.seed, "army": self.army, "round": self.round,
                        "n": n, "p": p, "unit": unit, "usage": usage,
                    }));
            }
        }
        let mut boundary = (p * GRID as f64).floor() as u64;
        let mut width = GRID;
        let mut sum = 0;
        let mut path = String::new();
        let domain = self.domain(unit, usage);
        while n > 0 && boundary > 0 {
            if boundary == width {
                return sum + n;
            }
            let left =
                ConsequenceSampler::from_domain(format!("{domain}\0tree/{path}")).binomial(n, 50);
            #[cfg(feature = "b1-profile")]
            TREE_STEPS.fetch_add(1, Ordering::Relaxed);
            let half = width / 2;
            if boundary <= half {
                n = left;
                path.push('0');
            } else {
                sum += left;
                n -= left;
                boundary -= half;
                path.push('1');
            }
            width = half;
        }
        sum
    }

    pub fn integer(&self, lower: i64, upper: i64, unit: &str, usage: &str) -> i64 {
        #[cfg(feature = "b1-profile")]
        INTEGER_CALLS.fetch_add(1, Ordering::Relaxed);
        if lower == upper {
            return lower;
        }
        let range = (upper - lower + 1) as u64;
        let bucket = GRID / range;
        let limit = bucket * range;
        let mut stream =
            ConsequenceSampler::from_domain(format!("{}\0uniform", self.domain(unit, usage)));
        loop {
            let bits = (stream.uniform() * GRID as f64).floor() as u64;
            if bits < limit {
                return lower + (bits / bucket) as i64;
            }
        }
    }
}
