#!/usr/bin/env bash
# Real Docker integration on a disposable Linux daemon (CI only by default).
set -Eeuo pipefail
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
source "$script_dir/release-common.sh"
[[ ${EUID:-$(id -u)} == 0 && ${ENGINE_TEST_DISPOSABLE_DOCKER:-} == 1 ]] || fail 'Requires root and ENGINE_TEST_DISPOSABLE_DOCKER=1 on a disposable daemon'
[[ -z $(container_for) ]] || fail 'An engine already exists; refusing to touch it'
if docker volume inspect waar-engine-demo_profile-saves >/dev/null 2>&1; then fail 'Profile volume already exists; refusing to touch it'; fi
work=$(mktemp -d /tmp/waar-stack-test-XXXXXXXX)
upload=$(mktemp -d /tmp/waar-engine-upload.XXXXXXXXXX)
export ENGINE_DEPLOY_ROOT="$work/deploy" ENGINE_LOG_ROOT="$work/logs"
cleanup() {
    status=$?
    trap - EXIT
    if ((status)); then
        find "$work/logs" -name '*.log' -type f -exec tail -n 60 {} \; 2>/dev/null || true
        container=$(container_for)
        [[ -z $container ]] || docker logs --tail 60 "$container" || true
    fi
    # Only the container created by this disposable-daemon test. The volume
    # remains until the disposable runner disappears; never remove a volume.
    DEMO_IMAGE=waar-engine-demo:stack-test-baseline docker compose -f "$script_dir/compose.yaml" rm -sf engine || true
    rm -rf -- "$work" "$upload"
    exit "$status"
}
trap cleanup EXIT
docker network inspect wai-edge >/dev/null 2>&1 || docker network create wai-edge >/dev/null
docker build -f "$script_dir/Dockerfile" -t waar-engine-demo:stack-test-baseline "$repo"
DEMO_IMAGE=waar-engine-demo:stack-test-baseline docker compose -f "$script_dir/compose.yaml" up -d --no-build --pull never engine
baseline=$(container_for)
wait_healthy "$baseline"
docker exec -i "$baseline" php <<'PHP'
<?php
$base='http://127.0.0.1:8080';
$profile=json_decode(file_get_contents($base.'/api/default-profile'),true,128,JSON_THROW_ON_ERROR)['data']['profile'];
$context=stream_context_create(['http'=>['method'=>'POST','timeout'=>10,'header'=>'Content-Type: application/json','content'=>json_encode(['name'=>'Deployment preservation fixture','profile'=>$profile],JSON_THROW_ON_ERROR)]]);
$saved=json_decode(file_get_contents($base.'/api/save-profile',false,$context),true,128,JSON_THROW_ON_ERROR);
if(($saved['ok']??false)!==true)throw new RuntimeException('Fixture save failed');
PHP
volume=$(docker volume inspect --format '{{.Mountpoint}}' waar-engine-demo_profile-saves)
original=$(fingerprint "$volume/profiles.json")
mkdir "$work/source"
# Exact application source needed by Docker, including the uncommitted scripts
# when run locally. Test SHAs are synthetic and never published as releases.
tar -cf "$work/source.tar" -C "$repo" autoload.php src resources public/workshop bin/workshop-router.php ops/demo .dockerignore engines/waar-cohort/rust/Cargo.toml engines/waar-cohort/rust/Cargo.lock engines/waar-cohort/rust/src
tar -xf "$work/source.tar" -C "$work/source"
sha=$(printf '%040d' 41)
tar -cf "$upload/release.tar" -C "$work/source" .
checksum=$(fingerprint "$upload/release.tar")
bash "$script_dir/activate-release.sh" engine-v0.1.0-rc.1 "$sha" "$checksum" "$upload/release.tar"
active=$(docker inspect --format '{{.Image}}' "$(container_for)")
[[ $(fingerprint "$volume/profiles.json") == "$original" ]]
bash "$script_dir/activate-release.sh" engine-v0.1.0-rc.1 "$sha" "$checksum" "$upload/release.tar"
[[ $(docker inspect --format '{{.Image}}' "$(container_for)") == "$active" ]]
# Exercise a real post-activation verification failure with a second image.
# The fixture requires a deliberately impossible runtime. Production source
# is untouched, and rollback must restore both image and current pointer.
sed -i "s/!== 'rust'/!== 'impossible-runtime'/" "$work/source/ops/demo/verify-release.sh"
failed_sha=$(printf '%040d' 42)
tar -cf "$upload/release.tar" -C "$work/source" .
checksum=$(fingerprint "$upload/release.tar")
if bash "$script_dir/activate-release.sh" engine-v0.1.0-rc.2 "$failed_sha" "$checksum" "$upload/release.tar"; then fail 'Invalid runtime accepted'; fi
[[ $(docker inspect --format '{{.Image}}' "$(container_for)") == "$active" ]]
[[ $(readlink "$ENGINE_DEPLOY_ROOT/current") == "$ENGINE_DEPLOY_ROOT/releases/$sha" ]]
[[ $(fingerprint "$volume/profiles.json") == "$original" ]]
wait_healthy "$(container_for)"
echo 'PASS real Docker: adoption, Rust HTTP, profiles, same-SHA retry and rollback'
