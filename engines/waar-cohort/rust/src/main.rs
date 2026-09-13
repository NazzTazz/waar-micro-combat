//! JSONL diagnostic CLI. A study still crosses JSON only once (operation=batch).
use serde_json::{json, Value};
use std::io::{self, BufRead, Write};

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
        _ => Err("operation must be resolve or batch".into()),
    }
}

fn main() {
    let mut output = io::BufWriter::new(io::stdout().lock());
    for line in io::stdin().lock().lines() {
        let result = match line {
            Ok(input) => run(&input).unwrap_or_else(|error| json!({"error": error})),
            Err(error) => json!({"error": error.to_string()}),
        };
        if writeln!(output, "{result}")
            .and_then(|_| output.flush())
            .is_err()
        {
            break;
        }
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
                "operation must be resolve or batch"
            );
        }
        assert!(run("not JSON").is_err());
        assert_eq!(run("{}").unwrap_err(), "missing request");
    }
}
