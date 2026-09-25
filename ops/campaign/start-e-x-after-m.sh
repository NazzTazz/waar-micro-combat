#!/usr/bin/env bash
set -euo pipefail

root=/home/debian/waar-campaign
results="$root/results"
mode=${1:-run}
if [[ "$mode" != run && "$mode" != --check-only ]]; then
    echo 'Usage: start-e-x-after-m.sh [--check-only]' >&2
    exit 2
fi

exec 9>"$root/.lock-handoff-e-x"
if ! flock -n 9; then
    echo 'E/X handoff already active' >&2
    exit 1
fi

check_m() {
    python3 - "$results" <<'PY'
import glob
import json
import os
import sys

results = sys.argv[1]
paths = [p for p in glob.glob(results + '/**/manifest.json', recursive=True)
         if os.path.basename(os.path.dirname(p)).startswith(('M-unit-', 'M-combat-'))]
if len(paths) > 45:
    print(f'ERROR: {len(paths)} M manifests, expected 45')
    raise SystemExit(2)
complete = exported = combats = 0
for path in paths:
    with open(path, encoding='utf-8') as stream:
        manifest = json.load(stream)
    if manifest.get('errors'):
        print(f'ERROR: errors in {path}')
        raise SystemExit(2)
    if manifest.get('status') == 'complete':
        complete += 1
        combats += manifest['completedCombats']
        if os.path.isfile(os.path.join(os.path.dirname(path), 'exports', 'results.csv')):
            exported += 1
if len(paths) == complete == exported == 45:
    if combats != 12720000:
        print(f'ERROR: completed M combats {combats}, expected 12720000')
        raise SystemExit(2)
    print(f'READY: {complete}/45 M manifests, {exported}/45 exports, {combats} combats')
else:
    print(f'WAIT: {complete}/45 M complete, {exported}/45 exports, {combats} complete combats')
    raise SystemExit(1)
PY
}

if [[ "$mode" == --check-only ]]; then
    check_m
    exit
fi

if [[ -e "$root/handoff-e-x-started" ]]; then
    echo 'E/X handoff was already started; inspect supervisor logs before a manual resume.' >&2
    exit 1
fi

echo "[$(date -Is)] Waiting for complete, exported M on the VPS"
until check_m; do
    status=$?
    if [[ "$status" -eq 2 ]]; then
        echo "[$(date -Is)] M verification failed; E/X not started" >&2
        exit 1
    fi
    sleep 60
done

# The marker prevents a second automatic handoff. Each shard remains manually resumable.
date -Is > "$root/handoff-e-x-started"
echo "[$(date -Is)] Starting E on worker A and X on worker B"
nohup bash "$root/run-next-vps.sh" e > "$root/supervisor-e.out.log" 2>&1 < /dev/null &
e_pid=$!
nohup bash "$root/run-next-vps.sh" x > "$root/supervisor-x.out.log" 2>&1 < /dev/null &
x_pid=$!
set +e
wait "$e_pid"
e_status=$?
wait "$x_pid"
x_status=$?
set -e
echo "[$(date -Is)] E exit=$e_status, X exit=$x_status"
if [[ "$e_status" -ne 0 || "$x_status" -ne 0 ]]; then
    exit 1
fi
