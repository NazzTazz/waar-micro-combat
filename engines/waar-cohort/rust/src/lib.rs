//! Waar cohort v2 — native cohort resolver and batch boundary.
//! The fixed-point and stochastic primitives are adapted from the imported
//! cohort engine; the active JSON/C ABI exposes only the version-2 contract.

use serde::{Deserialize, Deserializer, Serialize, Serializer};
use serde_json::json;
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::panic::{catch_unwind, AssertUnwindSafe};

mod addressed_random;
mod consequence_sampler;
mod v2;

const FIXED_SCALE: i64 = 1_000_000;
const NUMERIC_MODEL_VERSION: &str = "microstructure-6-half-up-v1";
const STOCHASTIC_ENGINE_VERSION: &str = "lcg31-binomial-normal-v1";

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Default)]
pub struct Micro(i64);
impl Micro {
    pub const ZERO: Self = Self(0);
    pub fn from_units(units: i64) -> Self {
        Self(units)
    }
    pub fn units(self) -> i64 {
        self.0
    }
    pub fn from_decimal_str(value: &str) -> Result<Self, String> {
        let value = value.trim();
        if value.is_empty() {
            return Err("empty fixed-point value".into());
        }
        let (negative, body) = match value.strip_prefix('-') {
            Some(rest) => (true, rest),
            None => (false, value),
        };
        let mut parts = body.split('.');
        let whole = parts.next().unwrap_or("0");
        let fraction = parts.next().unwrap_or("");
        if parts.next().is_some()
            || whole.is_empty()
            || !whole.bytes().all(|b| b.is_ascii_digit())
            || !fraction.bytes().all(|b| b.is_ascii_digit())
        {
            return Err(format!("invalid decimal value: {value}"));
        }
        if fraction.len() > 6 && fraction[6..].bytes().any(|b| b != b'0') {
            return Err(format!("more than 6 significant decimal places: {value}"));
        }
        let whole: i64 = whole
            .parse()
            .map_err(|_| format!("fixed-point overflow: {value}"))?;
        let mut padded = fraction[..fraction.len().min(6)].to_owned();
        while padded.len() < 6 {
            padded.push('0');
        }
        let fraction: i64 = padded.parse().unwrap_or(0);
        let units = whole
            .checked_mul(FIXED_SCALE)
            .and_then(|x| x.checked_add(fraction))
            .ok_or_else(|| format!("fixed-point overflow: {value}"))?;
        Ok(Self(if negative { -units } else { units }))
    }
    pub fn from_f64(value: f64) -> Result<Self, String> {
        if !value.is_finite() {
            return Err("fixed-point input must be finite".into());
        }
        Self::from_decimal_str(&format!("{value:.12}"))
    }
    pub fn multiply(self, rhs: Self) -> Result<Self, String> {
        let product = self.0 as i128 * rhs.0 as i128;
        let rounded = (product.abs() + FIXED_SCALE as i128 / 2) / FIXED_SCALE as i128
            * if product < 0 { -1 } else { 1 };
        Ok(Self(
            i64::try_from(rounded).map_err(|_| "fixed-point multiplication overflow")?,
        ))
    }
    pub fn subtract_repeated(self, times: u32, rhs: Self) -> Result<Self, String> {
        let value = self.0 as i128 - times as i128 * rhs.0 as i128;
        Ok(Self(
            i64::try_from(value).map_err(|_| "fixed-point subtraction overflow")?,
        ))
    }
    pub fn ceil_ratio(self, denominator: Self) -> Result<u64, String> {
        if self.0 < 0 || denominator.0 <= 0 {
            return Err("ceil_ratio expects numerator >= 0 and denominator > 0".into());
        }
        Ok((self.0 as u64 + denominator.0 as u64 - 1) / denominator.0 as u64)
    }
    pub fn format(self) -> String {
        let negative = self.0 < 0;
        let absolute = self.0.unsigned_abs();
        let whole = absolute / FIXED_SCALE as u64;
        let fraction = absolute % FIXED_SCALE as u64;
        let mut out = if fraction == 0 {
            whole.to_string()
        } else {
            let mut digits = format!("{fraction:06}");
            while digits.ends_with('0') {
                digits.pop();
            }
            format!("{whole}.{digits}")
        };
        if negative {
            out.insert(0, '-');
        }
        out
    }
}
impl Serialize for Micro {
    fn serialize<S>(&self, serializer: S) -> Result<S::Ok, S::Error>
    where
        S: Serializer,
    {
        serializer.serialize_str(&self.format())
    }
}
impl<'de> Deserialize<'de> for Micro {
    fn deserialize<D>(deserializer: D) -> Result<Self, D::Error>
    where
        D: Deserializer<'de>,
    {
        #[derive(Deserialize)]
        #[serde(untagged)]
        enum Input {
            String(String),
            Float(f64),
            Integer(i64),
        }
        match Input::deserialize(deserializer)? {
            Input::String(v) => Self::from_decimal_str(&v).map_err(serde::de::Error::custom),
            Input::Float(v) => Self::from_f64(v).map_err(serde::de::Error::custom),
            Input::Integer(v) => v
                .checked_mul(FIXED_SCALE)
                .map(Self)
                .ok_or_else(|| serde::de::Error::custom("fixed-point overflow")),
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum UnitType {
    Soldier,
    Spearman,
    Archer,
    Knight,
}
impl UnitType {
    pub const ALL: [Self; 4] = [Self::Soldier, Self::Spearman, Self::Archer, Self::Knight];
    pub const fn index(self) -> usize {
        match self {
            Self::Soldier => 0,
            Self::Spearman => 1,
            Self::Archer => 2,
            Self::Knight => 3,
        }
    }
}

#[derive(Debug, Clone)]
pub struct CombatRng {
    state: i64,
}
impl CombatRng {
    pub fn new(seed: i64) -> Self {
        Self {
            state: seed & 0x7fff_ffff,
        }
    }
    pub fn next_f64(&mut self) -> f64 {
        self.state = (1_103_515_245_i64 * self.state + 12_345) % 2_147_483_648_i64;
        self.state as f64 / 2_147_483_648.0
    }
    pub fn binomial(&mut self, trials: u32, probability: f64) -> u32 {
        if trials == 0 || probability <= 0.0 {
            return 0;
        }
        if probability >= 1.0 {
            return trials;
        }
        if trials <= 64 {
            let mut successes = 0;
            for _ in 0..trials {
                if self.next_f64() < probability {
                    successes += 1;
                }
            }
            return successes;
        }
        let u1 = self.next_f64().max(f64::MIN_POSITIVE);
        let u2 = self.next_f64();
        let normal = (-2.0 * u1.ln()).sqrt() * (2.0 * std::f64::consts::PI * u2).cos();
        let sample = (trials as f64 * probability
            + normal * (trials as f64 * probability * (1.0 - probability)).sqrt())
        .round() as i64;
        sample.clamp(0, trials as i64) as u32
    }
}

pub fn resolve_v2_json(input: &str) -> String {
    v2::resolve_json(input)
}
pub fn resolve_v2_batch_json(input: &str) -> String {
    v2::resolve_batch_json(input)
}

fn ffi_call(input_json: *const c_char, batch: bool) -> *mut c_char {
    let outcome = catch_unwind(AssertUnwindSafe(|| {
        if input_json.is_null() {
            return json!({"error":"input_json is null"}).to_string();
        }
        let bytes = unsafe { CStr::from_ptr(input_json) };
        let input = match bytes.to_str() {
            Ok(value) => value,
            Err(error) => {
                return json!({"error":format!("input is not valid UTF-8: {error}")}).to_string()
            }
        };
        if batch {
            v2::resolve_batch_json(input)
        } else {
            v2::resolve_json(input)
        }
    }));
    let output =
        outcome.unwrap_or_else(|_| json!({"error":"panic inside cohort engine"}).to_string());
    CString::new(output)
        .unwrap_or_else(|_| {
            CString::new(r#"{"error":"output contained an interior NUL"}"#).unwrap()
        })
        .into_raw()
}

#[no_mangle]
pub extern "C" fn resolve_combat_json(input_json: *const c_char) -> *mut c_char {
    ffi_call(input_json, false)
}
#[no_mangle]
pub extern "C" fn resolve_combat_batch_json(input_json: *const c_char) -> *mut c_char {
    ffi_call(input_json, true)
}
#[no_mangle]
pub extern "C" fn free_combat_string(ptr: *mut c_char) {
    if !ptr.is_null() {
        unsafe {
            drop(CString::from_raw(ptr));
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn fixed_point_operations_are_stable() {
        assert_eq!(
            Micro::from_decimal_str("1.250000").unwrap().format(),
            "1.25"
        );
        assert_eq!(
            Micro::from_decimal_str("0.000001")
                .unwrap()
                .multiply(Micro::from_decimal_str("1.5").unwrap())
                .unwrap()
                .format(),
            "0.000002"
        );
    }
    #[test]
    fn stochastic_vectors_match_php() {
        let mut rng = CombatRng::new(42);
        assert!((rng.next_f64() - 0.5823075897060335).abs() < 1e-15);
        assert!((rng.next_f64() - 0.5198187492787838).abs() < 1e-15);
    }
}
