#!/usr/bin/env bash
set -Eeuo pipefail
# This bootstrap is transferred as a file, never fed to bash -s. Keep the
# pre-extraction validation self-contained (the shared library is in the tar).
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ ${EUID:-$(id -u)} == 0 ]] || die 'Activation requires root'
[[ $# == 4 ]] || die 'Usage: activate-release.sh <tag> <sha> <checksum> <archive>'
tag=$1 sha=$2 checksum=$3 archive=$4
[[ $tag =~ ^engine-v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-rc\.[1-9][0-9]*$ ]] || die 'Invalid RC tag'
[[ $sha =~ ^[0-9a-f]{40}$ && $checksum =~ ^[0-9a-f]{64}$ ]] || die 'Invalid SHA/checksum'
[[ $archive =~ ^/tmp/waar-engine-upload\.[A-Za-z0-9]+/release.tar$ && -f $archive && ! -L $archive ]] || die 'Invalid archive path'
root=${ENGINE_DEPLOY_ROOT:-/opt/waar-micro-combat}
log_root=${ENGINE_LOG_ROOT:-/var/log/waar-micro-combat}
[[ $root =~ ^/[a-zA-Z0-9/_-]+$ && $root != / && $root != *'/../'* ]] || die 'Invalid deployment root'
[[ $log_root =~ ^/[a-zA-Z0-9/_-]+$ && $log_root != / && $log_root != *'/../'* ]] || die 'Invalid log root'
umask 077
install -d -m 0750 "$root" "$root/releases" "$log_root"
[[ ! -L $root && ! -L $root/releases && ! -L $log_root ]] || die 'Release/log directories must not be symlinks'
# Covers all entry points, including a manual rollback or a second SSH session.
exec 9>"$root/deploy.lock"
flock -n 9 || die 'Another engine deployment is active'
# A retained transaction is evidence of an interrupted deployment or failed
# rollback. Do not silently take a new baseline over possibly damaged data.
for pending in "$root"/transaction-*; do
    [[ ! -d $pending ]] || die "Unresolved recovery transaction: $pending"
done
log="$log_root/deploy-$sha-$(date -u +%Y%m%dT%H%M%SZ)-$$.log"
touch "$log"
chmod 640 "$log"
ln -sfn -- "$(basename "$log")" "$log_root/current.log"
exec > >(tee -a "$log") 2>&1
printf 'tag=%s sha=%s checksum=%s log=%s\n' "$tag" "$sha" "$checksum" "$log"
incoming=$(mktemp -d "$root/releases/.incoming-$sha-XXXXXX")
trap 'rm -rf -- "$incoming"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
# Copy to root-owned staging before verifying/extracting, so the transfer user
# cannot change the archive between checksum verification and extraction.
cp -- "$archive" "$incoming/archive.tar"
printf '%s  %s\n' "$checksum" "$incoming/archive.tar" | sha256sum --check --status
tar -tf "$incoming/archive.tar" > "$incoming/entries"
while IFS= read -r entry; do
    [[ $entry != /* && $entry != *'../'* && $entry != '..' && $entry != *$'\r'* ]] || die 'Unsafe archive entry'
    case "$entry" in .waar-release-*|.image-id|.deployed-compose.yaml) die 'Reserved release metadata in archive';; esac
done < "$incoming/entries"
# Git source needs only regular files/directories. Refuse links/devices rather
# than trusting tar path handling during privileged extraction.
tar -tvf "$incoming/archive.tar" > "$incoming/types"
if grep -qvE '^[-d]' "$incoming/types"; then die 'Archive links and special files are forbidden'; fi
mkdir "$incoming/source"
tar --extract --file "$incoming/archive.tar" --directory "$incoming/source" --no-same-owner --no-same-permissions
# A private staging umask must not turn Docker COPY inputs into root-only
# files: Apache reads copied sources as www-data. Keep source readable while
# removing all special bits and group/other write permissions.
chmod -R a+rX,go-w,u-s,g-s,o-t "$incoming/source"
for file in ops/demo/deploy.sh ops/demo/verify-release.sh ops/demo/release-common.sh ops/demo/compose.yaml ops/demo/Dockerfile; do
    [[ -f $incoming/source/$file ]] || die "Release file missing: $file"
done
release="$root/releases/$sha"
if [[ -e $release || -L $release ]]; then
    [[ -d $release && ! -L $release ]] || die 'Invalid existing release'
    [[ $(cat "$release/.waar-release-sha") == "$sha" && $(cat "$release/.waar-release-archive-sha256") == "$checksum" ]] || die 'Release checksum conflict'
    diff -qr --exclude=.waar-release-sha --exclude=.waar-release-archive-sha256 --exclude=.image-id --exclude=.deployed-compose.yaml "$incoming/source" "$release" || die 'Immutable release source has changed'
else
    printf '%s\n' "$sha" > "$incoming/source/.waar-release-sha"
    printf '%s\n' "$checksum" > "$incoming/source/.waar-release-archive-sha256"
    chmod -R go-w "$incoming/source"
    mv -- "$incoming/source" "$release"
fi
export ENGINE_DEPLOY_ROOT="$root" ENGINE_ACTIVATION_LOCKED=1
bash "$release/ops/demo/deploy.sh" "$sha" "$release"
printf 'activation=ok current=%s\n' "$release"
