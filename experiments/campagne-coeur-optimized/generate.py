"""Derive the optimized campaign from the immutable historical source plans.

This script only writes plans and an inventory. It never runs combat.
"""

import hashlib
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
SOURCE = HERE.parent / "campagne-coeur"
VERSION = "sha256-splitmix-occupancy/1"


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_json(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main():
    historical = json.loads((SOURCE / "campaign-manifest.json").read_text(encoding="utf-8"))
    assert historical["totalPlans"] == len(historical["plans"]) == 159
    records = []
    omitted = []
    for source_record in historical["plans"]:
        name = source_record["plan"]
        source_path = SOURCE / name
        if name.startswith("W-factor-"):
            omitted.append(name)
            continue
        plan = json.loads(source_path.read_text(encoding="utf-8"))
        plan["stochasticEngineVersion"] = VERSION
        plan["profile"] = "../campagne-coeur/reference-profile.json"
        plan["output"] = "../../reports/campagne-coeur-optimized/" + source_path.stem
        if name.startswith("M-weather-preset-"):
            # Neutral's A-only/B-only rows have the same effective weather as
            # its both row. Keep exactly one shared-weather context per duel.
            plan["scenarios"] = [
                scenario for scenario in plan["scenarios"]
                if scenario["weather"]["A"] == scenario["weather"]["B"]
                and scenario["id"].endswith("-both")
            ]
            assert plan["limits"]["maxCombats"] == 360_000
            plan["limits"]["maxCombats"] = 120_000
        if name == "M-combat-maxRounds.json":
            axis = plan["axes"]["value"]
            assert axis["path"] == "combat.maxRounds"
            axis["values"] = [value for value in axis["values"] if value <= 20]
            assert plan["limits"]["maxCombats"] == 840_000
            plan["limits"]["maxCombats"] = 720_000
        assert plan["scenarios"], name
        assert all(s["weather"]["A"] == s["weather"]["B"] for s in plan["scenarios"]), name
        assert all(not axis["path"].startswith("weather.") for axis in plan["axes"].values()), name
        target = HERE / name
        write_json(target, plan)
        records.append({
            "plan": name,
            "phase": source_record["phase"],
            "sourceSha256": sha256(source_path),
            "planSha256": sha256(target),
            "scenarios": len(plan["scenarios"]),
            "repetitionsPerDirection": plan["sampling"]["repetitions"],
        })
    assert len(omitted) == 80 and len(records) == 79
    write_json(HERE / "manifest.json", {
        "schemaVersion": "waar-optimized-campaign-inventory/1",
        "sourceManifestSha256": sha256(SOURCE / "campaign-manifest.json"),
        "profileSha256": sha256(SOURCE / "reference-profile.json"),
        "stochasticEngineVersion": VERSION,
        "weatherPolicy": "same-preset-for-A-and-B; fixed-preset-effects",
        "maximumRounds": 20,
        "sourcePlans": len(historical["plans"]),
        "omittedWeatherFactorPlans": len(omitted),
        "plans": records,
    })
    print(f"{len(records)} plans generated; {len(omitted)} weather-factor plans omitted")


if __name__ == "__main__":
    main()
