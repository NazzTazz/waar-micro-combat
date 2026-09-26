"""Summarize observed parameter effects and simulation cost from VPS exports.

This is descriptive: outcomes are 2,000-sample aggregates, and elapsedSeconds
also reflects how parameter values change battle length and composition.
"""

import csv
import json
import statistics
import sys
from collections import defaultdict
from pathlib import Path


ROOT = Path(sys.argv[1] if len(sys.argv) > 1 else "/home/debian/waar-campaign-optimized")
PLANS = ROOT / "context/experiments/campagne-coeur-optimized"
RESULTS = ROOT / "results"
inventory = json.loads((PLANS / "manifest.json").read_text(encoding="utf-8"))


def metrics(row):
    samples = int(row["samples"])
    initial_a = sum(int(row[f"A_initial_{unit}"]) for unit in ("soldier", "spearman", "archer", "knight"))
    initial_b = sum(int(row[f"B_initial_{unit}"]) for unit in ("soldier", "spearman", "archer", "knight"))
    result = {
        "win": int(row["attacker_wins"]) / samples,
        "draw": int(row["draws"]) / samples,
        "rounds": float(row["mean_rounds"]),
        "lossA": float(row["A_bench_economic_loss_rate"]),
        "lossB": float(row["B_bench_economic_loss_rate"]),
    }
    for side, initial in (("A", initial_a), ("B", initial_b)):
        for outcome in ("dead", "wounded", "prisoners"):
            result[f"{outcome}{side}"] = sum(
                float(row[f"{side}_projected_{outcome}_{unit}"])
                for unit in ("soldier", "spearman", "archer", "knight")
            ) / max(initial, 1)
    return result


def distribution(values):
    if not values:
        return None
    ordered = sorted(values)
    return {"median": statistics.median(ordered), "p90": ordered[int(0.9 * (len(ordered) - 1))],
            "max": ordered[-1], "overFivePoints": sum(value >= 0.05 for value in values)}


def value_key(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"))


summary = []
for entry in inventory["plans"]:
    name = entry["plan"]
    stem = Path(name).stem
    plan = json.loads((PLANS / name).read_text(encoding="utf-8"))
    manifest = json.loads((RESULTS / stem / "manifest.json").read_text(encoding="utf-8"))
    combats = manifest["completedCombats"]
    simulation_seconds = manifest["simulationSeconds"]
    item = {"plan": name, "axis": None, "combats": combats,
            "simulationSeconds": simulation_seconds, "combatsPerSimulationSecond": combats / simulation_seconds}
    if list(plan["axes"]) == ["value"]:
        item["axis"] = plan["axes"]["value"]["path"]
        baseline_value = plan["axes"]["value"]["values"][0]
        contexts = defaultdict(dict)
        experiment_values = {}
        with (RESULTS / stem / "exports/results.csv").open(newline="", encoding="utf-8") as stream:
            for row in csv.DictReader(stream):
                axes = json.loads(row["axes_json"])
                value = axes["value"] if isinstance(axes, dict) and "value" in axes else baseline_value
                experiment_values[row["experiment_id"]] = value
                key = (row["scenario_id"], row["composition_id"], row["attacker"], row["defender"])
                contexts[key][value_key(value)] = metrics(row)
        ranges = defaultdict(list)
        for variants in contexts.values():
            if len(variants) < 2:
                continue
            for metric in next(iter(variants.values())):
                values = [record[metric] for record in variants.values()]
                ranges[metric].append(max(values) - min(values))
        item["contexts"] = len(contexts)
        item["matchedContexts"] = len(ranges["win"])
        item["ranges"] = {metric: distribution(values) for metric, values in ranges.items()}
        item["fromBaseline"] = {}
        baseline_key = value_key(baseline_value)
        for value in plan["axes"]["value"]["values"][1:]:
            alternative_key = value_key(value)
            differences = defaultdict(list)
            for variants in contexts.values():
                if baseline_key not in variants or alternative_key not in variants:
                    continue
                for metric in ("win", "rounds", "lossA", "lossB", "woundedA", "prisonersA"):
                    differences[metric].append(abs(variants[baseline_key][metric] - variants[alternative_key][metric]))
            item["fromBaseline"][alternative_key] = {
                metric: distribution(values) for metric, values in differences.items()
            }
        if item["axis"].endswith("strikesPerAttack"):
            item["strikesPairs"] = {}
            for left, right in ((1, 2), (1, 3), (1, 5), (1, 8), (8, 12), (8, 16)):
                differences = defaultdict(list)
                for variants in contexts.values():
                    if value_key(left) not in variants or value_key(right) not in variants:
                        continue
                    for metric in ("win", "rounds", "lossA", "lossB"):
                        differences[metric].append(abs(variants[value_key(left)][metric] - variants[value_key(right)][metric]))
                item["strikesPairs"][f"{left}:{right}"] = {
                    metric: distribution(values) for metric, values in differences.items()
                }
        if item["axis"].endswith("strikesPerAttack") or item["axis"] in (
            "combat.maxRounds", "combat.surrender", "combat.woundDamageThreshold"
        ):
            seconds = defaultdict(float)
            lots = defaultdict(int)
            for path in (RESULTS / stem / "lots").glob("*.json"):
                lot = json.loads(path.read_text(encoding="utf-8"))
                if lot["experimentId"] not in experiment_values:
                    continue
                value = value_key(experiment_values[lot["experimentId"]])
                seconds[value] += lot["elapsedSeconds"]
                lots[value] += lot["response"]["totalCombats"]
            item["combatsPerSecondByValue"] = {value: lots[value] / seconds[value] for value in seconds}
    summary.append(item)

print(json.dumps({"totalCombats": sum(row["combats"] for row in summary), "plans": summary}))
