"""Audit completed optimized campaign results without modifying them."""

import hashlib
import json
import sys
from pathlib import Path


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main(root):
    plans = root / "context/experiments/campagne-coeur-optimized"
    results = root / "results"
    inventory = json.loads((plans / "manifest.json").read_text(encoding="utf-8"))
    assert len(inventory["plans"]) == 79
    assert inventory["maximumRounds"] == 20
    assert inventory["weatherPolicy"] == "same-preset-for-A-and-B; fixed-preset-effects"
    expected = inventory["plans"]
    assert len({entry["plan"] for entry in expected}) == len(expected)
    assert {path.name for path in results.iterdir() if path.is_dir()} == {
        Path(entry["plan"]).stem for entry in expected
    }

    total = 0
    binary_hashes = set()
    source_hashes = set()
    for entry in expected:
        name = entry["plan"]
        plan_path = plans / name
        assert sha256(plan_path) == entry["planSha256"], name
        plan = json.loads(plan_path.read_text(encoding="utf-8"))
        folder = results / plan_path.stem
        result = json.loads((folder / "manifest.json").read_text(encoding="utf-8"))
        identity = result["identity"]
        engine = identity["engine"]
        assert result["status"] == "complete" and result["errors"] == [], name
        assert result["completedCombats"] == result["preview"]["combats"] == plan["limits"]["maxCombats"], name
        assert identity["planSha256"] == entry["planSha256"], name
        assert identity["profileSha256"] == inventory["profileSha256"], name
        assert identity["sampling"] == plan["sampling"], name
        assert engine["kind"] == "rust", name
        assert engine["stochasticEngineVersion"] == inventory["stochasticEngineVersion"], name
        assert (folder / "exports/results.csv").stat().st_size > 0, name
        binary_hashes.add(engine["binarySha256"])
        source_hashes.add(engine["trackedDiffSha256"])
        total += result["completedCombats"]

    assert total == 22_544_000, total
    assert len(binary_hashes) == len(source_hashes) == 1
    print(json.dumps({"plans": len(expected), "combats": total, "exports": len(expected),
                      "binarySha256": next(iter(binary_hashes)),
                      "trackedDiffSha256": next(iter(source_hashes))}))


if __name__ == "__main__":
    main(Path(sys.argv[1] if len(sys.argv) > 1 else "/home/debian/waar-campaign-optimized"))
