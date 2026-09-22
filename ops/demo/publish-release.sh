#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
source "$script_dir/release-common.sh"
: "${ENGINE_RELEASE_TAG:?}" "${ENGINE_RELEASE_SHA:?}" "${ENGINE_RELEASE_ARCHIVE:?}" "${ENGINE_RELEASE_CHECKSUM:?}"
: "${ENGINE_VPS_HOST:?}" "${ENGINE_VPS_USER:?}" "${ENGINE_SSH_KEY_FILE:?}" "${ENGINE_SSH_KNOWN_HOSTS_FILE:?}"
valid_sha "$ENGINE_RELEASE_SHA" || fail 'Invalid SHA'
valid_tag "$ENGINE_RELEASE_TAG" || fail 'Invalid RC tag'
[[ $ENGINE_RELEASE_CHECKSUM =~ ^[0-9a-f]{64}$ ]] || fail 'Invalid checksum'
[[ $ENGINE_VPS_HOST =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || fail 'Invalid SSH host'
[[ $ENGINE_VPS_USER =~ ^[a-z_][a-z0-9_-]*$ ]] || fail 'Invalid SSH user'
port=${ENGINE_VPS_PORT:-22}
[[ $port =~ ^[0-9]{1,5}$ ]] || fail 'Invalid SSH port'
((10#$port >= 1 && 10#$port <= 65535)) || fail 'Invalid SSH port'
[[ -r $ENGINE_RELEASE_ARCHIVE && -r $ENGINE_SSH_KEY_FILE && -r $ENGINE_SSH_KNOWN_HOSTS_FILE ]] || fail 'Unreadable input file'
printf '%s  %s\n' "$ENGINE_RELEASE_CHECKSUM" "$ENGINE_RELEASE_ARCHIVE" | sha256sum --check --status
bootstrap_checksum=$(sha256sum "$script_dir/activate-release.sh" | cut -d ' ' -f 1)
remote="$ENGINE_VPS_USER@$ENGINE_VPS_HOST"
options=(-i "$ENGINE_SSH_KEY_FILE" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$ENGINE_SSH_KNOWN_HOSTS_FILE" -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4)
upload=$(ssh "${options[@]}" -p "$port" "$remote" 'umask 077; mktemp -d /tmp/waar-engine-upload.XXXXXXXXXX')
[[ $upload =~ ^/tmp/waar-engine-upload\.[A-Za-z0-9]+$ ]] || fail 'Unexpected SSH staging path'
cleanup() {
    status=$?
    trap - EXIT
    # Only explicit files in the validated unique staging directory.
    ssh "${options[@]}" -p "$port" "$remote" "rm -f -- '$upload/release.tar' '$upload/activate.sh'; rmdir -- '$upload'" >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT
scp "${options[@]}" -P "$port" "$ENGINE_RELEASE_ARCHIVE" "$remote:$upload/release.tar"
scp "${options[@]}" -P "$port" "$script_dir/activate-release.sh" "$remote:$upload/activate.sh"
# timeout is remote: runner cancellation / SSH loss cannot leave an unbounded
# transaction. The child handles TERM and rolls back; recovery files survive
# a rollback failure. A host crash/SIGKILL still requires operator recovery.
ssh "${options[@]}" -p "$port" "$remote" \
    "printf '%s  %s\n' '$bootstrap_checksum' '$upload/activate.sh' | sha256sum --check --status && sudo -n timeout --signal=TERM --kill-after=180s 20m /bin/bash '$upload/activate.sh' '$ENGINE_RELEASE_TAG' '$ENGINE_RELEASE_SHA' '$ENGINE_RELEASE_CHECKSUM' '$upload/release.tar'" </dev/null
