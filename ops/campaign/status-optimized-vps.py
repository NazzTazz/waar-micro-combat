"""Read-only progress snapshot for the two optimized VPS campaign shards."""

import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path("/home/debian/waar-campaign-optimized")
PLANS = ROOT / "context/experiments/campagne-coeur-optimized"
RESULTS = ROOT / "results"


def main():
    inventory = json.loads((PLANS / "manifest.json").read_text(encoding="utf-8"))
    shards = []
    for slot in (0, 1):
        selected = inventory["plans"][slot::2]
        current = None
        completed = 0
        errors = 0
        finished = 0
        planned = 0
        for entry in selected:
            path = PLANS / entry["plan"]
            plan = json.loads(path.read_text(encoding="utf-8"))
            planned += plan["limits"]["maxCombats"]
            manifest_path = RESULTS / path.stem / "manifest.json"
            manifest = json.loads(manifest_path.read_text(encoding="utf-8")) if manifest_path.is_file() else None
            if manifest:
                completed += manifest["completedCombats"]
                errors += len(manifest["errors"])
                if manifest["status"] == "complete":
                    finished += 1
            if current is None and (manifest is None or manifest["status"] != "complete"):
                lots = manifest["completedLots"] if manifest else 0
                total_lots = (manifest["preview"]["experiments"] * plan["sampling"]["repetitions"] // plan["sampling"]["batchSize"]) if manifest else None
                current = {"plan": entry["plan"], "completedLots": lots, "totalLots": total_lots,
                           "status": manifest["status"] if manifest else "pending"}
        shards.append({"slot": slot, "completedCombats": completed, "plannedCombats": planned,
                       "completePlans": finished, "plans": len(selected), "errors": errors, "current": current})
    print(json.dumps({"at": datetime.now(timezone.utc).isoformat(), "totalCompleted": sum(s["completedCombats"] for s in shards),
                      "totalPlanned": sum(s["plannedCombats"] for s in shards), "shards": shards}))


if __name__ == "__main__":
    main()
