#!/usr/bin/env bash
# Sourced by the release scripts; no deployment side effects here.
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
valid_sha() { [[ $1 =~ ^[0-9a-f]{40}$ ]]; }
valid_tag() { [[ $1 =~ ^engine-v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-rc\.[1-9][0-9]*$ ]]; }
fingerprint() {
    if [[ -f $1 && ! -L $1 ]]; then sha256sum -- "$1" | cut -d ' ' -f 1;
    elif [[ ! -e $1 && ! -L $1 ]]; then printf 'absent\n';
    else return 1; fi
}
wait_healthy() {
    local container=$1 health attempt
    for ((attempt=0; attempt<60; attempt++)); do
        health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container") || return 1
        case "$health" in healthy) return 0;; unhealthy|missing) return 1;; esac
        sleep 2
    done
    return 1
}
container_for() {
    docker ps -aq --filter label=com.docker.compose.project=waar-engine-demo --filter label=com.docker.compose.service=engine
}
assert_mount() {
    local actual
    actual=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/waar-profiles"}}{{.Type}}:{{.Name}}:{{.RW}}{{end}}{{end}}' "$1")
    [[ $actual == volume:waar-engine-demo_profile-saves:true ]] || fail 'Unexpected profile volume mount'
}
