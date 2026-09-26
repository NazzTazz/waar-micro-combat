//! Issue #25 microbenchmark: replay captured RNG calls, no combat.
#![allow(dead_code)]
#[path = "../addressed_random.rs"]
mod addressed_random;
#[path = "../consequence_sampler.rs"]
mod consequence_sampler;

use addressed_random::AddressedRandom;
use serde_json::{json, Value};
use std::hint::black_box;
use std::io::{self, Read};
use std::time::Instant;

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let mut source = String::new();
    io::stdin().read_to_string(&mut source)?;
    let samples: Vec<Value> = serde_json::from_str(&source)?;
    if samples.is_empty() || samples.len() > 64 {
        return Err("expected 1..64 captured calls".into());
    }
    let repetitions = 100u64;
    let mut checksum = 0u64;
    let start = Instant::now();
    for _ in 0..repetitions {
        for sample in &samples {
            let random = AddressedRandom::new(
                sample["seed"].as_i64().ok_or("seed")?,
                sample["army"].as_str().ok_or("army")?,
                sample["round"].as_u64().ok_or("round")? as u32,
            );
            checksum = checksum.wrapping_add(
                black_box(random.domain(
                    sample["unit"].as_str().ok_or("unit")?,
                    sample["usage"].as_str().ok_or("usage")?,
                ))
                .len() as u64,
            );
        }
    }
    let domain_ns = start.elapsed().as_nanos();
    let start = Instant::now();
    for _ in 0..repetitions {
        for sample in &samples {
            let random = AddressedRandom::new(
                sample["seed"].as_i64().ok_or("seed")?,
                sample["army"].as_str().ok_or("army")?,
                sample["round"].as_u64().ok_or("round")? as u32,
            );
            checksum = checksum.wrapping_add(black_box(random.binomial(
                sample["n"].as_u64().ok_or("n")? as u32,
                sample["p"].as_f64().ok_or("p")?,
                sample["unit"].as_str().ok_or("unit")?,
                sample["usage"].as_str().ok_or("usage")?,
            )) as u64);
        }
    }
    let binomial_ns = start.elapsed().as_nanos();
    println!(
        "{}",
        json!({"schemaVersion":"waar-b1-rng-microbenchmark/1","samples":samples.len(),"repetitions":repetitions,"domainNs":domain_ns,"binomialInclusiveNs":binomial_ns,"checksum":checksum})
    );
    Ok(())
}
