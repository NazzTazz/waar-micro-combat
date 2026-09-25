#!/usr/bin/env bash
set -euo pipefail

shard=${1:?Usage: run-next-vps.sh e|x|w1|w2 [--check-only]}
check_only=${2:-}
if [[ "$check_only" != "" && "$check_only" != "--check-only" ]]; then
    echo 'Usage: run-next-vps.sh e|x|w1|w2 [--check-only]' >&2
    exit 2
fi

root=/home/debian/waar-campaign
context="$root/context/experiments/campagne-coeur"
inventory="$root/context/active-next-plans.csv"
results="$root/results"
logs="$root/logs"
# Pin the exact image already used by the M worker; a mutable tag must not drift.
image=sha256:aa4fad4fe88895d78237baabcf6d563bc4c8fbd522064e0a3c5bdf0bf9d20425

case "$shard" in
    e) expected_count=6; expected_combats=2448000 ;;
    x) expected_count=16; expected_combats=2520000 ;;
    w1|w2) expected_count=45; expected_combats=13320000 ;;
    *) echo 'Usage: run-next-vps.sh e|x|w1|w2 [--check-only]' >&2; exit 2 ;;
esac

# Selection and all plan hashes are checked before any combat is started.
selection=$(python3 - "$shard" "$inventory" "$context" "$expected_count" "$expected_combats" <<'PY'
import csv
import hashlib
import json
import os
import re
import sys

shard, inventory, context, count, combats = sys.argv[1:]
count, combats = int(count), int(combats)
w1 = {'blizzard', 'cloudy', 'neutral', 'rain', 'storm'}
w2 = {'canicule', 'heat', 'snow', 'thunderstorm', 'wind'}

def weather(name):
    match = re.fullmatch(r'M-weather-preset-([^.]+)\.json', name)
    if match:
        return match.group(1)
    match = re.fullmatch(r'W-factor-([^-]+)-.+\.json', name)
    return match.group(1) if match else None

with open(inventory, newline='', encoding='utf-8-sig') as stream:
    rows = list(csv.DictReader(stream))
selected = []
for row in rows:
    phase, name = row['phase'], row['plan']
    include = (shard == 'e' and phase == 'E') or (shard == 'x' and phase == 'X')
    if shard in ('w1', 'w2') and phase == 'W':
        condition = weather(name)
        if condition is None:
            raise SystemExit(f'Unknown W weather in {name}')
        include = condition in (w1 if shard == 'w1' else w2)
    if not include:
        continue
    if shard in ('e', 'x') and row['dependency'] != 'M':
        raise SystemExit(f'Unexpected prerequisite for {name}: {row["dependency"]}')
    path = os.path.join(context, name)
    if not os.path.isfile(path):
        raise SystemExit(f'Missing plan: {path}')
    with open(path, 'rb') as stream:
        raw = stream.read()
    if hashlib.sha256(raw).hexdigest() != row['planSha256'].lower():
        raise SystemExit(f'Plan SHA mismatch: {name}')
    plan = json.loads(raw)
    if plan['limits']['maxCombats'] != int(row['maxCombats']):
        raise SystemExit(f'Combat limit mismatch: {name}')
    if (plan['sampling']['repetitions'], plan['sampling']['batchSize'], plan['sampling']['baseSeed']) != (2000, 100, 42):
        raise SystemExit(f'Sampling mismatch: {name}')
    if not plan['profile'].endswith('reports/campaign-manual-sol/profile.json'):
        raise SystemExit(f'Profile path mismatch: {name}')
    if shard in ('e', 'x') and any(s['weather'] != {'A': 'neutral', 'B': 'neutral'} for s in plan['scenarios']):
        raise SystemExit(f'Non-neutral E/X plan: {name}')
    selected.append(row)

if len(selected) != count or len({r['plan'] for r in selected}) != count:
    raise SystemExit(f'{shard}: expected {count} distinct plans, got {len(selected)}')
actual = sum(int(r['maxCombats']) for r in selected)
if actual != combats:
    raise SystemExit(f'{shard}: expected {combats} combats, got {actual}')
for row in selected:
    print(row['plan'])
PY
)
mapfile -t plans <<< "$selection"
echo "[$(date -Is)] $shard validated: ${#plans[@]} plans, $expected_combats combats"
if [[ "$check_only" == '--check-only' ]]; then
    exit 0
fi

mkdir -p "$results" "$logs"
exec 9>"$root/.lock-next-$shard"
if ! flock -n 9; then
    echo "Shard $shard is already running" >&2
    exit 1
fi

# Preserve the original M gate. W1 may use E's freed slot while X continues;
# W2 still waits for both E and X, so at most two campaign workers run.
python3 - "$shard" "$results" "$inventory" <<'PY'
import csv
import glob
import json
import os
import sys

shard, results, inventory = sys.argv[1:]
if shard in ('e', 'x'):
    paths = [p for p in glob.glob(results + '/**/manifest.json', recursive=True)
             if os.path.basename(os.path.dirname(p)).startswith(('M-unit-', 'M-combat-'))]
    if len(paths) != 45:
        raise SystemExit(f'M prerequisite: expected 45 VPS manifests, got {len(paths)}')
    expected_total = 12720000
else:
    with open(inventory, newline='', encoding='utf-8-sig') as stream:
        required = ('E',) if shard == 'w1' else ('E', 'X')
        rows = [r for r in csv.DictReader(stream) if r['phase'] in required]
    paths = [os.path.join(results, os.path.splitext(r['plan'])[0], 'manifest.json') for r in rows]
    expected_count = 6 if shard == 'w1' else 22
    if len(paths) != expected_count:
        raise SystemExit(f'{required} prerequisite: expected {expected_count} plans, got {len(paths)}')
    expected_total = 2448000 if shard == 'w1' else 4968000

completed = 0
for path in paths:
    if not os.path.isfile(path):
        raise SystemExit(f'Prerequisite manifest missing: {path}')
    with open(path, encoding='utf-8') as stream:
        manifest = json.load(stream)
    if manifest['status'] != 'complete' or manifest['errors'] or not os.path.isfile(os.path.join(os.path.dirname(path), 'exports', 'results.csv')):
        raise SystemExit(f'Prerequisite incomplete or unexported: {path}')
    completed += manifest['completedCombats']
if completed != expected_total:
    raise SystemExit(f'Prerequisite combat count: {completed} != {expected_total}')
PY

for relative in "${plans[@]}"; do
    plan="$context/$relative"
    name=$(basename "$plan" .json)
    echo "[$(date -Is)] $shard resume $relative"
    sudo -n docker run --rm --name "waar-campaign-$shard" --network none --cpus 1.0 --memory 600m --pids-limit 64 \
        --security-opt no-new-privileges --read-only --tmpfs /tmp:size=32m \
        --user "$(id -u):$(id -g)" \
        -v "$context:/app/experiments/campagne-coeur:ro" \
        -v "$results:/app/reports/campagne-coeur:rw" \
        "$image" resume "/app/experiments/campagne-coeur/$relative" \
        > "$logs/$name.run.log" 2>&1

    manifest="$results/${relative%.json}/manifest.json"
    python3 - "$manifest" "$plan" <<'PY'
import json
import sys

with open(sys.argv[1], encoding='utf-8') as stream:
    manifest = json.load(stream)
with open(sys.argv[2], encoding='utf-8') as stream:
    plan = json.load(stream)
if (manifest['status'] != 'complete' or manifest['errors'] or
        manifest['completedCombats'] != plan['limits']['maxCombats']):
    raise SystemExit(f'Incomplete plan: {sys.argv[2]}')
PY

    sudo -n docker run --rm --name "waar-campaign-$shard-export" --network none --cpus 1.0 --memory 600m --pids-limit 64 \
        --security-opt no-new-privileges --read-only --tmpfs /tmp:size=32m \
        --user "$(id -u):$(id -g)" \
        -v "$context:/app/experiments/campagne-coeur:ro" \
        -v "$results:/app/reports/campagne-coeur:rw" \
        "$image" export "/app/experiments/campagne-coeur/$relative" \
        > "$logs/$name.export.log" 2>&1
    echo "[$(date -Is)] $shard complete $relative"
done

echo "[$(date -Is)] $shard shard complete: ${#plans[@]} plans, $expected_combats combats"
