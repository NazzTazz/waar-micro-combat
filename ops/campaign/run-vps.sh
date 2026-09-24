#!/usr/bin/env bash
set -euo pipefail

shard=${1:?Usage: run-vps.sh a|b}
root=/home/debian/waar-campaign
context="$root/context/experiments/campagne-coeur"
results="$root/results"
logs="$root/logs"
image=waar-campaign:bd79dff-m
mkdir -p "$results" "$logs"

case "$shard" in
    a)
        plans=(
            "$context"/M-unit-knight-*.json
            "$context"/M-unit-spearman-*.json
            "$context"/segments/M-combat-*-b12000.json
            "$context"/segments/M-combat-*-b120000.json
        )
        expected_count=26
        expected_combats=7440000
        ;;
    b)
        plans=(
            "$context"/M-unit-soldier-*.json
            "$context"/M-unit-archer-capturable-fixed-counts.json
            "$context"/M-unit-archer-cost-fixed-budget.json
            "$context"/M-unit-archer-cost-fixed-counts.json
            "$context"/M-unit-archer-defendingEfficiency-fixed-counts.json
            "$context"/M-unit-archer-strikesPerAttack-fixed-counts.json
            "$context"/M-unit-archer-structure-fixed-counts.json
            "$context"/segments/M-combat-*-b360000.json
        )
        expected_count=19
        expected_combats=5280000
        ;;
    *) echo 'Usage: run-vps.sh a|b' >&2; exit 2 ;;
esac

python3 - "$expected_count" "$expected_combats" "${plans[@]}" <<'PY'
import json
import os
import sys

count = int(sys.argv[1])
combats = int(sys.argv[2])
paths = sys.argv[3:]
if len(paths) != count or len(set(paths)) != count or any(not os.path.isfile(path) for path in paths):
    raise SystemExit(f'Unexpected shard files: {len(paths)} plans')
actual = sum(json.load(open(path, encoding='utf-8'))['limits']['maxCombats'] for path in paths)
if actual != combats:
    raise SystemExit(f'Unexpected shard combat count: {actual}')
print(f'Shard validated: {count} plans, {combats} combats', flush=True)
PY

for plan in "${plans[@]}"; do
    name=$(basename "$plan" .json)
    relative=${plan#"$context"/}
    echo "[$(date -Is)] $shard resume $relative"
    sudo -n docker run --rm --name "waar-campaign-$shard" --network none --cpus 0.7 --memory 600m --pids-limit 64 \
        --security-opt no-new-privileges --read-only --tmpfs /tmp:size=32m \
        --user "$(id -u):$(id -g)" \
        -v "$results:/app/reports/campagne-coeur:rw" \
        "$image" resume "/app/experiments/campagne-coeur/$relative" \
        > "$logs/$name.run.log" 2>&1

    if [[ "$relative" == segments/* ]]; then
        manifest="$results/segments/$name/manifest.json"
    else
        manifest="$results/$name/manifest.json"
    fi
    python3 - "$manifest" "$plan" <<'PY'
import json
import sys

with open(sys.argv[1], encoding='utf-8') as stream:
    manifest = json.load(stream)
with open(sys.argv[2], encoding='utf-8') as stream:
    plan = json.load(stream)
expected = plan['limits']['maxCombats']
if manifest['status'] != 'complete' or manifest['completedCombats'] != expected or manifest['errors']:
    raise SystemExit(f'Incomplete plan: {sys.argv[2]}')
PY

    sudo -n docker run --rm --name "waar-campaign-$shard-export" --network none --cpus 0.7 --memory 600m --pids-limit 64 \
        --security-opt no-new-privileges --read-only --tmpfs /tmp:size=32m \
        --user "$(id -u):$(id -g)" \
        -v "$results:/app/reports/campagne-coeur:rw" \
        "$image" export "/app/experiments/campagne-coeur/$relative" \
        > "$logs/$name.export.log" 2>&1
    echo "[$(date -Is)] $shard complete $relative"
done

echo "[$(date -Is)] $shard shard complete: $expected_count plans, $expected_combats combats"
