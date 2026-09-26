#!/usr/bin/env bash
set -euo pipefail

# Run only on the campaign VPS, after transferring a reviewed checkout and
# building the dedicated fast-impact image from ops/campaign/Dockerfile.
shard=${1:?Usage: run-optimized-vps.sh 0|1 sha256:image-id [--check-only]}
image=${2:?Usage: run-optimized-vps.sh 0|1 sha256:image-id [--check-only]}
check_only=${3:-}
[[ "$shard" == 0 || "$shard" == 1 ]] || exit 2
[[ "$image" =~ ^sha256:[0-9a-f]{64}$ ]] || { echo 'Pinned image ID required' >&2; exit 2; }
[[ -z "$check_only" || "$check_only" == --check-only ]] || exit 2

root=/home/debian/waar-campaign-optimized
context="$root/context"
plans="$context/experiments/campagne-coeur-optimized"
results="$root/results"
logs="$root/logs"
sudo -n docker image inspect "$image" >/dev/null

# Audit the whole inventory before selecting either shard. Neither old plans
# nor old results are read or written by this launcher.
selection=$(python3 - "$plans" "$shard" <<'PY'
import hashlib
import json
from pathlib import Path
import sys

directory, shard = Path(sys.argv[1]), int(sys.argv[2])
manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
assert manifest['schemaVersion'] == 'waar-optimized-campaign-inventory/1'
assert manifest['maximumRounds'] == 20
assert manifest['stochasticEngineVersion'] == 'sha256-splitmix-occupancy/1'
assert len(manifest['plans']) == 79
source = directory.parent / 'campagne-coeur'
assert hashlib.sha256((source / 'reference-profile.json').read_bytes()).hexdigest() == manifest['profileSha256']
assert hashlib.sha256((source / 'campaign-manifest.json').read_bytes()).hexdigest() == manifest['sourceManifestSha256']
names = set()
for index, entry in enumerate(manifest['plans']):
    name = entry['plan']
    assert name not in names and Path(name).name == name and name.endswith('.json')
    names.add(name)
    path = directory / name
    assert hashlib.sha256(path.read_bytes()).hexdigest() == entry['planSha256'], name
    old_path = source / name
    assert hashlib.sha256(old_path.read_bytes()).hexdigest() == entry['sourceSha256'], name
    plan = json.loads(path.read_text(encoding='utf-8'))
    assert plan['stochasticEngineVersion'] == manifest['stochasticEngineVersion'], name
    assert plan['sampling'] == {'repetitions': 2000, 'baseSeed': 42, 'batchSize': 100}, name
    assert plan['profile'] == '../campagne-coeur/reference-profile.json', name
    assert plan['output'] == '../../reports/campagne-coeur-optimized/' + path.stem, name
    assert all(s['weather']['A'] == s['weather']['B'] for s in plan['scenarios']), name
    assert all(not axis['path'].startswith('weather.') for axis in plan['axes'].values()), name
    assert all(axis['path'] != 'combat.maxRounds' or max(axis['values']) <= 20 for axis in plan['axes'].values()), name
    if index % 2 == shard:
        print(name)
PY
)
mapfile -t selected <<< "$selection"
sudo -n docker run --rm --network none --read-only --entrypoint php "$image" -r '
$dir = "/app/experiments/campagne-coeur-optimized";
$m = json_decode(file_get_contents("$dir/manifest.json"), true, 128, JSON_THROW_ON_ERROR);
if (count($m["plans"]) !== 79 || hash_file("sha256", "/app/experiments/campagne-coeur/reference-profile.json") !== $m["profileSha256"]) { exit(1); }
foreach ($m["plans"] as $row) {
    if (hash_file("sha256", "$dir/".$row["plan"]) !== $row["planSha256"]) { exit(1); }
}
'
echo "Validated 79 plans; shard $shard has ${#selected[@]} plans."
[[ "$check_only" == --check-only ]] && exit 0

mkdir -p "$results" "$logs"
exec 9>"$root/.lock-$shard"
flock -n 9 || { echo "Shard $shard already running" >&2; exit 1; }

# The old campaign may still be using the VPS quota. A new shard stops rather
# than silently competing with it.
if sudo -n docker ps --format '{{.Names}}' | grep -E '^waar-campaign-(a|b|e|x|w1|w2)$'; then
    echo 'Historical campaign worker still running' >&2
    exit 1
fi

for name in "${selected[@]}"; do
    stem=${name%.json}
    plan="/app/experiments/campagne-coeur-optimized/$name"
    echo "[$(date -Is)] $shard $name"
    sudo -n docker run --rm --name "waar-campaign-opt-$shard" --network none \
        --cpus 1.0 --memory 600m --pids-limit 64 --security-opt no-new-privileges \
        --read-only --tmpfs /tmp:size=32m --user "$(id -u):$(id -g)" \
        -v "$results:/app/reports/campagne-coeur-optimized:rw" \
        "$image" resume "$plan" >"$logs/$stem.run.log" 2>&1
    python3 - "$results/$stem/manifest.json" "$plans/$name" <<'PY'
import json
import sys
with open(sys.argv[1], encoding='utf-8') as stream:
    result = json.load(stream)
with open(sys.argv[2], encoding='utf-8') as stream:
    plan = json.load(stream)
assert result['status'] == 'complete' and not result['errors']
assert result['completedCombats'] == result['preview']['combats']
assert result['completedCombats'] <= plan['limits']['maxCombats']
assert result['identity']['engine']['stochasticEngineVersion'] == 'sha256-splitmix-occupancy/1'
PY
    sudo -n docker run --rm --name "waar-campaign-opt-$shard-export" --network none \
        --cpus 1.0 --memory 600m --pids-limit 64 --security-opt no-new-privileges \
        --read-only --tmpfs /tmp:size=32m --user "$(id -u):$(id -g)" \
        -v "$results:/app/reports/campagne-coeur-optimized:rw" \
        "$image" export "$plan" >"$logs/$stem.export.log" 2>&1
    test -s "$results/$stem/exports/results.csv"
done

echo "[$(date -Is)] shard $shard complete"
