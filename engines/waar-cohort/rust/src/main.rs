//! JSONL diagnostic CLI. A study still crosses JSON only once (operation=batch).
use serde_json::{json, Value};
use std::io::{self, BufRead, Write};
#[cfg(feature = "b1-profile")]
use std::time::Instant;

fn run(input: &str) -> Result<Value, String> {
    // Windows shell pipelines can prepend the UTF-8 byte-order mark.
    let document: Value =
        serde_json::from_str(input.trim_start_matches('\u{feff}')).map_err(|e| e.to_string())?;
    let request = document.get("request").ok_or("missing request")?.clone();
    match document.get("operation").and_then(Value::as_str) {
        Some("resolve") => {
            let output = waar_cohort::resolve_v2_json(
                &serde_json::to_string(&request).map_err(|e| e.to_string())?,
            );
            serde_json::from_str(&output).map_err(|e| e.to_string())
        }
        Some("batch") => {
            let output = waar_cohort::resolve_v2_batch_json(
                &serde_json::to_string(&request).map_err(|e| e.to_string())?,
            );
            serde_json::from_str(&output).map_err(|e| e.to_string())
        }
        Some("campaignBatch") => {
            let output = waar_cohort::resolve_v2_campaign_batch_json(
                &serde_json::to_string(&request).map_err(|e| e.to_string())?,
            );
            serde_json::from_str(&output).map_err(|e| e.to_string())
        }
        _ => Err("operation must be resolve, batch or campaignBatch".into()),
    }
}

fn main() {
    let mut output = io::BufWriter::new(io::stdout().lock());
    for line in io::stdin().lock().lines() {
        #[cfg(feature = "b1-profile")]
        let b1_started = Instant::now();
        let result = match line {
            Ok(input) => run(&input).unwrap_or_else(|error| json!({"error": error})),
            Err(error) => json!({"error": error.to_string()}),
        };
        #[cfg(feature = "b1-profile")]
        let b1_run_ns = b1_started.elapsed().as_nanos();
        #[cfg(feature = "b1-profile")]
        let b1_serialized = result.to_string();
        #[cfg(feature = "b1-profile")]
        let b1_serialize_ns = b1_started.elapsed().as_nanos() - b1_run_ns;
        #[cfg(feature = "b1-profile")]
        let write_result = writeln!(output, "{b1_serialized}").and_then(|_| output.flush());
        #[cfg(not(feature = "b1-profile"))]
        let write_result = writeln!(output, "{result}").and_then(|_| output.flush());
        if write_result.is_err() {
            break;
        }
        #[cfg(feature = "b1-profile")]
        eprintln!(
            "B1_TRANSPORT {}",
            json!({
                "runNs": b1_run_ns,
                "serializeNs": b1_serialize_ns,
                "writeFlushNs": b1_started.elapsed().as_nanos() - b1_run_ns - b1_serialize_ns,
                "outputBytes": b1_serialized.len(),
            })
        );
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_windows_utf8_bom_and_preserves_protocol_errors() {
        for prefix in ["", "\u{feff}"] {
            let input = format!("{prefix}{{\"operation\":\"oops\",\"request\":{{}}}}");
            assert_eq!(
                run(&input).unwrap_err(),
                "operation must be resolve, batch or campaignBatch"
            );
        }
        assert!(run("not JSON").is_err());
        assert_eq!(run("{}").unwrap_err(), "missing request");
    }
}
