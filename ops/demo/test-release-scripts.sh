#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
source "$script_dir/release-common.sh"
for script in "$script_dir/"*.sh "$repo/tests/deployment/"*.sh; do bash -n "$script"; done
valid_tag engine-v0.1.0-rc.1
for tag in latest engine-v01.0.0-rc.1 engine-v1.0.0-rc.0 'engine-v1.0.0-rc.1;id'; do
    if valid_tag "$tag"; then fail "Accepted invalid tag: $tag"; fi
done
if env -i PATH="$PATH" ENGINE_RELEASE_SHA=invalid bash "$script_dir/publish-release.sh" >/dev/null 2>&1; then fail 'Incomplete publication accepted'; fi
if grep -F 'StrictHostKeyChecking=no' "$script_dir/publish-release.sh"; then fail 'SSH host checking disabled'; fi
grep -q 'cancel-in-progress: false' "$repo/.github/workflows/deploy-engine-candidate.yml"
grep -q 'read_only: true' "$script_dir/compose.yaml"
grep -q 'name: waar-engine-demo' "$script_dir/compose.yaml"

# Integration of the real Bash scripts against a stateful Docker substitute.
# Root is required by the same production guard; CI runs this script with sudo.
if [[ ${EUID:-$(id -u)} != 0 ]]; then
    echo 'Run with sudo for transactional tests.' >&2
    exit 1
fi
work=$(mktemp -d /tmp/waar-deploy-test-XXXXXXXX)
upload=$(mktemp -d /tmp/waar-engine-upload.XXXXXXXXXX)
cleanup() { rm -rf -- "$work" "$upload"; }
trap cleanup EXIT
mkdir "$work/bin" "$work/source"
cp "$repo/tests/deployment/fake-docker.sh" "$work/bin/docker"
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH"
mkdir -p "$work/source/ops/demo"
cp "$script_dir/"*.sh "$script_dir/compose.yaml" "$script_dir/Dockerfile" "$work/source/ops/demo/"
tar -cf "$upload/release.tar" -C "$work/source" .
checksum=$(sha256sum "$upload/release.tar" | cut -d ' ' -f 1)
sha=$(printf '%040d' 3)
old_id="sha256:$(printf '%064d' 1)"
tag=engine-v0.1.0-rc.1
setup_case() {
    case_root="$work/$1"
    mkdir -p "$case_root/docker/volume"
    export FAKE_DOCKER_STATE="$case_root/docker" ENGINE_DEPLOY_ROOT="$case_root/releases-root" ENGINE_LOG_ROOT="$case_root/logs"
    printf '%s\n' "$old_id" > "$FAKE_DOCKER_STATE/image"
    printf 'manual-old-tag\n' > "$FAKE_DOCKER_STATE/tag"
    printf '%s\n' "$sha" > "$FAKE_DOCKER_STATE/sha"
    printf '{"existing":"untouched"}\n' > "$FAKE_DOCKER_STATE/volume/profiles.json"
    printf '# old-config-sentinel\nservices:\n  engine:\n    image: manual-old-tag\n' > "$case_root/old.yaml"
    printf '%s\n' "$case_root/old.yaml" > "$FAKE_DOCKER_STATE/config"
    unset FAIL_AT
}
activate() { bash "$script_dir/activate-release.sh" "$tag" "$sha" "$checksum" "$upload/release.tar"; }
expect_failure() { if activate > "$case_root/output" 2>&1; then fail 'Expected activation failure'; fi; }
assert_restored() {
    [[ $(cat "$FAKE_DOCKER_STATE/image") == "$old_id" ]] || fail 'Previous image not restored'
    [[ $(cat "$FAKE_DOCKER_STATE/volume/profiles.json") == '{"existing":"untouched"}' ]] || fail 'Profiles not restored'
    [[ ! -e $ENGINE_DEPLOY_ROOT/current && ! -L $ENGINE_DEPLOY_ROOT/current ]] || fail 'First-adoption pointer not restored'
    grep -q 'rollback=ok' "$case_root/output"
    # The Compose file used to restore the first manual install must survive.
    [[ -f $(cat "$FAKE_DOCKER_STATE/config") ]] || fail 'Rollback config disappeared'
}
for failure in partial-up unhealthy runtime corruption signal; do
    setup_case "$failure"
    export FAIL_AT=$failure
    expect_failure
    assert_restored
    echo "PASS rollback after $failure"
done
setup_case absent-store
rm "$FAKE_DOCKER_STATE/volume/profiles.json"
export FAIL_AT=corruption
expect_failure
[[ ! -e $FAKE_DOCKER_STATE/volume/profiles.json ]]
[[ $(cat "$FAKE_DOCKER_STATE/image") == "$old_id" ]]
grep -q 'rollback=ok' "$case_root/output"
echo 'PASS rollback restores initially absent store'
setup_case build
export FAIL_AT=build
expect_failure
[[ $(cat "$FAKE_DOCKER_STATE/image") == "$old_id" && ! -e $ENGINE_DEPLOY_ROOT/current ]]
echo 'PASS build failure leaves production untouched'
setup_case rollback
export FAIL_AT=rollback
expect_failure
grep -q 'rollback=FAILED' "$case_root/output"
find "$ENGINE_DEPLOY_ROOT" -path '*/transaction-*/profiles.json' | grep -q .
echo 'PASS failed rollback preserves recovery copy'
unset FAIL_AT
expect_failure
grep -q 'Unresolved recovery transaction' "$case_root/output"
echo 'PASS unresolved recovery blocks the next deployment'
setup_case repeat
activate > "$case_root/output" 2>&1
[[ $(readlink "$ENGINE_DEPLOY_ROOT/current") == "$ENGINE_DEPLOY_ROOT/releases/$sha" ]]
activate >> "$case_root/output" 2>&1
[[ $(wc -l < "$FAKE_DOCKER_STATE/builds") == 1 ]]
echo 'PASS same SHA reuses exact image'
source_mode=$(stat -c '%a' "$ENGINE_DEPLOY_ROOT/releases/$sha/ops/demo/Dockerfile")
(( (8#$source_mode & 0444) == 0444 && (8#$source_mode & 0022) == 0 ))
[[ $(stat -c '%a' "$ENGINE_DEPLOY_ROOT/releases/$sha/ops/demo") == 755 ]]
echo 'PASS extracted sources remain readable by runtime users'
printf 'sha256:%064d\n' 99 > "$FAKE_DOCKER_STATE/built"
expect_failure
grep -q 'Immutable image tag has moved' "$case_root/output"
echo 'PASS retagged image refused'
setup_case missing-image
activate > "$case_root/output" 2>&1
rm "$FAKE_DOCKER_STATE/built"
expect_failure
grep -q 'refusing to rebuild' "$case_root/output"
echo 'PASS missing recorded image refused'
setup_case concurrency
mkdir -p "$ENGINE_DEPLOY_ROOT"
exec 7>"$ENGINE_DEPLOY_ROOT/deploy.lock"
flock -x 7
expect_failure
grep -q 'Another engine deployment' "$case_root/output"
flock -u 7
echo 'PASS concurrent activation refused'
setup_case tampering
activate > "$case_root/output" 2>&1
printf '\n# changed\n' >> "$ENGINE_DEPLOY_ROOT/releases/$sha/ops/demo/deploy.sh"
expect_failure
grep -q 'Immutable release source has changed' "$case_root/output"
echo 'PASS modified release refused'
setup_case checksum
if bash "$script_dir/activate-release.sh" "$tag" "$sha" "$(printf '%064d' 0)" "$upload/release.tar" > "$case_root/output" 2>&1; then fail 'Bad checksum accepted'; fi
[[ ! -e $ENGINE_DEPLOY_ROOT/current ]]
echo 'PASS bad checksum refused before activation'
setup_case stale-current
activate > "$case_root/output" 2>&1
printf '%s\n' "$old_id" > "$FAKE_DOCKER_STATE/image"
expect_failure
grep -q 'Current release and running container disagree' "$case_root/output"
echo 'PASS stale current pointer refused'

# Validate every untrusted transport field with otherwise complete inputs.
export ENGINE_RELEASE_TAG=$tag ENGINE_RELEASE_SHA=$sha ENGINE_RELEASE_CHECKSUM=$checksum ENGINE_RELEASE_ARCHIVE="$upload/release.tar"
export ENGINE_VPS_HOST=example.invalid ENGINE_VPS_USER=deploy ENGINE_VPS_PORT=22 ENGINE_SSH_KEY_FILE="$upload/release.tar" ENGINE_SSH_KNOWN_HOSTS_FILE="$upload/release.tar"
for invalid in 'ENGINE_RELEASE_TAG=engine-v1.0.0-rc.0' 'ENGINE_RELEASE_SHA=bad' 'ENGINE_RELEASE_CHECKSUM=bad' 'ENGINE_VPS_HOST=-option' 'ENGINE_VPS_USER=root;id' 'ENGINE_VPS_PORT=65536'; do
    if env "$invalid" bash "$script_dir/publish-release.sh" > "$case_root/transport" 2>&1; then fail "Invalid input accepted: $invalid"; fi
    grep -q 'ERROR: Invalid' "$case_root/transport"
done
echo 'PASS invalid transport fields refused'
