#!/usr/bin/env bash
set -euo pipefail

# The X supervisor started with 0.7 vCPU in its already-parsed loop body.
# Raise each newly created X container without restarting the supervisor or plan.
supervisor_pid=${1:?Usage: keep-x-at-one-vcpu.sh SUPERVISOR_PID}
while [[ -r "/proc/$supervisor_pid/cmdline" ]] && \
      tr '\0' ' ' < "/proc/$supervisor_pid/cmdline" | grep -Fq '/home/debian/waar-campaign/run-next-vps.sh x'; do
    id=$(sudo -n docker ps -q --filter 'name=^/waar-campaign-x$')
    if [[ -n "$id" ]]; then
        quota=$(sudo -n docker inspect --format='{{.HostConfig.NanoCpus}}' "$id")
        if [[ "$quota" != 1000000000 ]]; then
            sudo -n docker update --cpus 1.0 "$id"
            echo "[$(date -Is)] X container $id raised to 1.0 vCPU"
        fi
    fi
    sleep 2
done
echo "[$(date -Is)] X supervisor $supervisor_pid exited; CPU watcher stopped"
