#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
source "$script_dir/release-common.sh"
[[ $# == 2 ]] || fail 'Usage: deploy.sh <sha> <release-directory>'
sha=$1 release=$2
valid_sha "$sha" || fail 'Invalid SHA'
root=${ENGINE_DEPLOY_ROOT:-/opt/waar-micro-combat}
[[ $root == /* && $root != / && $release == "$root/releases/$sha" ]] || fail 'Invalid release path'
[[ ${EUID:-$(id -u)} == 0 ]] || fail 'Deployment requires root'
[[ ${ENGINE_ACTIVATION_LOCKED:-} == 1 ]] || fail 'Invoke through activate-release.sh'
image="waar-engine-demo:$sha"
export DEMO_IMAGE=$image
export COMPOSE_PROJECT_NAME=waar-engine-demo
compose="$release/ops/demo/compose.yaml"
docker compose -f "$compose" config --quiet
docker network inspect wai-edge >/dev/null
# An existing installation and volume are deliberately required. Provisioning
# an empty production store is a separate, explicit bootstrap operation.
volume=waar-engine-demo_profile-saves
mount=$(docker volume inspect --format '{{.Mountpoint}}' "$volume")
[[ $mount == /* && -d $mount && ! -L $mount ]] || fail 'Profile volume is unavailable'
previous=$(container_for)
[[ -n $previous && $previous != *$'\n'* ]] || fail 'Exactly one existing engine container is required'
assert_mount "$previous"
previous_image=$(docker inspect --format '{{.Image}}' "$previous")
[[ $previous_image =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'Previous image ID is invalid'
wait_healthy "$previous" || fail 'Previous container is not healthy'
previous_link=''
if [[ -L $root/current ]]; then
    previous_link=$(readlink -f -- "$root/current")
    [[ $previous_link == "$root/releases/"* && -d $previous_link ]] || fail 'Invalid current link'
    [[ -f $previous_link/.image-id && $(cat "$previous_link/.image-id") == "$previous_image" && -f $previous_link/.deployed-compose.yaml ]] || fail 'Current release and running container disagree'
elif [[ -e $root/current ]]; then fail 'current must be a symlink'; fi

transaction=$(mktemp -d "$root/transaction-$sha-XXXXXX")
chmod 700 "$transaction"
install -d -m 0700 "$root/configurations"
# Compose records its config path in container labels. Keep rollback configs
# available even after a successful first-adoption rollback (no current yet).
rollback_config="$root/configurations/$(basename "$transaction").yaml"
switched=0 committed=0 snapshot=0 rollback_failed=0
cleanup() {
    local status=$? restored container
    trap - EXIT HUP INT TERM
    set +e
    if (( switched && ! committed )); then
        printf 'rollback=started previous_image=%s\n' "$previous_image"
        # Stop all failed candidate writers before restoring the store.
        container=$(container_for)
        if [[ -n $container && $container != *$'\n'* ]]; then
            docker stop -t 10 "$container" >/dev/null || rollback_failed=1
        else rollback_failed=1; fi
        if (( snapshot && ! rollback_failed )); then
            restored=$(fingerprint "$mount/profiles.json")
            if [[ $restored != "$before" ]]; then
                if [[ $before == absent ]]; then
                    rm -f -- "$mount/profiles.json" || rollback_failed=1
                else
                    # Same filesystem rename, preserving owner and permissions.
                    cp -p -- "$transaction/profiles.json" "$mount/.profiles-restore-$sha" &&
                        mv -f -- "$mount/.profiles-restore-$sha" "$mount/profiles.json" || rollback_failed=1
                fi
            fi
        fi
        [[ $(fingerprint "$mount/profiles.json") == "$before" ]] || rollback_failed=1
        docker compose -f "$rollback_config" up -d --no-deps --no-build --pull never engine || rollback_failed=1
        # The old application may still use blocking flock. Let pending saves
        # proceed before checking its health, after restoration was verified.
        flock -u 8 || rollback_failed=1
        container=$(container_for)
        if [[ -n $container && $container != *$'\n'* ]]; then
            [[ $(docker inspect --format '{{.Image}}' "$container") == "$previous_image" ]] || rollback_failed=1
            wait_healthy "$container" || rollback_failed=1
        else rollback_failed=1; fi
        if [[ -n $previous_link ]]; then
            ln -s -- "$previous_link" "$transaction/current-rollback" && mv -Tf -- "$transaction/current-rollback" "$root/current" || rollback_failed=1
        elif [[ -L $root/current ]]; then
            rm -f -- "$root/current" || rollback_failed=1
        fi
        if (( rollback_failed )); then
            printf 'rollback=FAILED recovery_files=%s\n' "$transaction" >&2
        else printf 'rollback=ok\n'; fi
        status=1
    fi
    # Never delete the last recovery copy when restoration failed.
    if (( ! rollback_failed )); then rm -rf -- "$transaction"; fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# Reuse the previous release's resolved configuration. On first adoption,
# discover the single Compose source recorded by Docker; refuse ambiguity.
if [[ -n $previous_link && -f $previous_link/.deployed-compose.yaml ]]; then
    old_compose="$previous_link/.deployed-compose.yaml"
else
    old_compose=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}' "$previous")
    [[ $old_compose == /* && $old_compose != *,* && -f $old_compose ]] || fail 'Cannot recover original Compose configuration; bootstrap must supply one existing Compose file'
fi
# The override pins the actual previous image, even if its historical tag moved.
printf 'services:\n  engine:\n    image: %s\n' "$previous_image" > "$transaction/image.yaml"
DEMO_IMAGE="$previous_image" docker compose -f "$old_compose" -f "$transaction/image.yaml" config > "$rollback_config"
docker compose -f "$rollback_config" config --quiet

if docker image inspect "$image" >/dev/null 2>&1; then
    [[ -f $release/.image-id ]] || fail 'Existing image has no trusted release record'
    image_id=$(cat "$release/.image-id")
    [[ $(docker image inspect --format '{{.Id}}' "$image") == "$image_id" ]] || fail 'Immutable image tag has moved'
else
    [[ ! -e $release/.image-id ]] || fail 'Recorded image is missing; refusing to rebuild the same SHA'
    docker build --pull --label "org.opencontainers.image.revision=$sha" -f "$release/ops/demo/Dockerfile" -t "$image" "$release"
    image_id=$(docker image inspect --format '{{.Id}}' "$image")
    [[ $image_id =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'Invalid built image ID'
    printf '%s\n' "$image_id" > "$release/.image-id"
fi
[[ $(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image_id") == "$sha" ]] || fail 'Image provenance mismatch'

# Keep the same lock inode forever. Creation must not leave a root-only lock
# which would prevent www-data from saving after the deployment.
[[ ! -L $mount/profiles.lock ]] || fail 'Profile lock must not be a symlink'
if [[ ! -e $mount/profiles.lock ]]; then
    (umask 000; set -o noclobber; : > "$mount/profiles.lock") 2>/dev/null || true
fi
[[ -f $mount/profiles.lock ]] || fail 'Profile lock unavailable'
chown --reference="$mount" "$mount/profiles.lock"
chmod 600 "$mount/profiles.lock"
exec 8<>"$mount/profiles.lock"
flock -x -w 30 8 || fail 'Profile writer did not finish in time'
before=$(fingerprint "$mount/profiles.json")
if [[ $before != absent ]]; then cp -p -- "$mount/profiles.json" "$transaction/profiles.json"; fi
snapshot=1
docker compose -f "$compose" config > "$transaction/candidate.yaml"
switched=1 # up may fail AFTER replacing the old container
docker compose -f "$transaction/candidate.yaml" up -d --no-deps --no-build --pull never engine
container=$(container_for)
[[ -n $container && $container != *$'\n'* ]] || fail 'Candidate container missing or ambiguous'
timeout --signal=TERM --kill-after=10s 240s bash "$script_dir/verify-release.sh" "$sha" "$image_id" "$container"
[[ $(fingerprint "$mount/profiles.json") == "$before" ]] || fail 'Profile store changed during deployment'
# Metadata is separate from the immutable source checksum. Commit the pointer
# last; an error in either operation remains inside the rollback boundary.
cp -- "$transaction/candidate.yaml" "$release/.deployed-compose.yaml"
chmod 600 "$release/.deployed-compose.yaml"
ln -s -- "$release" "$transaction/current-next"
mv -Tf -- "$transaction/current-next" "$root/current"
committed=1
printf 'deployment=ok sha=%s image_id=%s profiles=unchanged rollback=not-needed\n' "$sha" "$image_id"
