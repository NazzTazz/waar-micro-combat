//! Dedicated consequence stream; see specification §9.5. Never used by physics.
use sha2::{Digest, Sha256};

pub const VERSION: &str = "sha256-counter52-binomial-btrs/1";

pub struct ConsequenceSampler {
    domain: String,
    counter: u64,
}

impl ConsequenceSampler {
    pub fn new(seed: i64, side: &str, unit: &str, stage: &str) -> Self {
        Self {
            domain: format!(
                "{VERSION}\0wounded-capture-then-compress/3\0{seed}\0{side}\0{unit}\0{stage}"
            ),
            counter: 0,
        }
    }

    pub fn uniform(&mut self) -> f64 {
        let hash = Sha256::digest(format!("{}\0{}", self.domain, self.counter).as_bytes());
        self.counter += 1;
        let bits = u64::from_be_bytes(hash[..8].try_into().unwrap()) >> 12;
        (bits as f64 + 0.5) / 4503599627370496.0
    }

    pub fn binomial(&mut self, n: u32, percent: u32) -> u32 {
        assert!(percent <= 100);
        if n == 0 || percent == 0 {
            return 0;
        }
        if percent == 100 {
            return n;
        }
        let p = percent.min(100 - percent) as f64 / 100.0;
        let sample = if (n as f64) * p < 30.0 {
            let limit = (-p).ln_1p();
            let mut position = 0.0;
            let mut sample = 0;
            loop {
                position += (self.uniform().ln() / limit).floor() + 1.0;
                if position > n as f64 {
                    break;
                }
                sample += 1;
            }
            sample
        } else {
            self.btrs(n, p)
        };
        if percent > 50 {
            n - sample
        } else {
            sample
        }
    }

    fn btrs(&mut self, count: u32, p: f64) -> u32 {
        let n = count as f64;
        let sigma = (n * p * (1.0 - p)).sqrt();
        let b = 1.15 + 2.53 * sigma;
        let a = -0.0873 + 0.0248 * b + 0.01 * p;
        let center = n * p + 0.5;
        let squeeze = 0.92 - 4.2 / b;
        let alpha = (2.83 + 5.1 / b) * sigma;
        let mode = ((n + 1.0) * p).floor();
        let odds = p / (1.0 - p);
        loop {
            let u = self.uniform() - 0.5;
            let v = self.uniform();
            let us = 0.5 - u.abs();
            let k = ((2.0 * a / us + b) * u + center).floor();
            if k < 0.0 || k > n {
                continue;
            }
            if us >= 0.07 && v <= squeeze {
                return k as u32;
            }
            let bound = (mode + 0.5) * ((mode + 1.0) / (odds * (n - mode + 1.0))).ln()
                + (n + 1.0) * ((k - mode) / (n - k + 1.0)).ln_1p()
                + (k + 0.5) * (odds * (n - k + 1.0) / (k + 1.0)).ln()
                + tail(mode)
                + tail(n - mode)
                - tail(k)
                - tail(n - k);
            if (v * alpha / (a / (us * us) + b)).ln() <= bound {
                return k as u32;
            }
        }
    }
}

fn tail(k: f64) -> f64 {
    let small = [
        0.08106146679532726,
        0.04134069595540929,
        0.02767792568499834,
        0.02079067210376509,
        0.01664469118982119,
        0.01387612882307075,
        0.01189670994589177,
        0.01041126526197210,
        0.009255462182712733,
        0.008330563433362871,
    ];
    if k < 10.0 {
        return small[k as usize];
    }
    let x = k + 1.0;
    let square = x * x;
    (1.0 / 12.0
        - (1.0 / 360.0 - (1.0 / 1260.0 - (1.0 / 1680.0 - 1.0 / 1188.0 / square) / square) / square)
            / square)
        / x
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn consequence_vectors() {
        let vectors: serde_json::Value = serde_json::from_str(include_str!(
            "../../tests/fixtures/consequence-v3-vectors.json"
        ))
        .unwrap();
        for row in vectors["samples"].as_array().unwrap() {
            let mut sampler = ConsequenceSampler::new(
                row["seed"].as_i64().unwrap(),
                row["side"].as_str().unwrap(),
                row["type"].as_str().unwrap(),
                row["stage"].as_str().unwrap(),
            );
            let actual = sampler.binomial(
                row["n"].as_u64().unwrap() as u32,
                row["percent"].as_u64().unwrap() as u32,
            );
            assert_eq!(actual as u64, row["sample"].as_u64().unwrap(), "{row}");
            assert_eq!(
                (sampler.uniform() * 4503599627370496.0).floor() as u64,
                row["nextBits"].as_u64().unwrap(),
                "stream consumption: {row}"
            );
        }
    }
}
