#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
source "$script_dir/release-common.sh"
[[ $# == 3 ]] || fail 'Usage: verify-release.sh <sha> <image-id> <container>'
sha=$1 image_id=$2 container=$3
valid_sha "$sha" || fail 'Invalid SHA'
[[ $image_id =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'Invalid image ID'
[[ $(docker inspect --format '{{.Image}}' "$container") == "$image_id" ]] || fail 'Running image ID mismatch'
[[ $(docker inspect --format '{{.Config.Image}}' "$container") == "waar-engine-demo:$sha" ]] || fail 'Running image tag mismatch'
[[ $(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image_id") == "$sha" ]] || fail 'Image revision mismatch'
assert_mount "$container"
[[ $(docker inspect --format '{{.HostConfig.ReadonlyRootfs}}' "$container") == true ]] || fail 'Read-only root filesystem is required'
[[ $(docker inspect --format '{{json .HostConfig.SecurityOpt}}' "$container") == *'"no-new-privileges:true"'* ]] || fail 'no-new-privileges is required'
ports=$(docker inspect --format '{{json .HostConfig.PortBindings}}' "$container")
[[ $ports == '{}' || $ports == null ]] || fail 'Host ports must not be published'
wait_healthy "$container" || fail 'Container did not become healthy'
# PHP is already installed. The source is read from stdin, never written into
# the container. All HTTP checks are bounded; the duel performs two tiny fights.
docker exec -i "$container" php <<'PHP'
<?php
function request(string $path, ?array $body = null): array {
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        $options = ['method' => $body === null ? 'GET' : 'POST', 'timeout' => 5,
            'ignore_errors' => true, 'header' => "Content-Type: application/json\r\nConnection: close\r\n"];
        if ($body !== null) $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        $raw = @file_get_contents('http://127.0.0.1:8080'.$path, false, stream_context_create(['http' => $options]));
        $status = $http_response_header[0] ?? '';
        if (preg_match('/\s429\s/', $status)) { sleep(1); continue; }
        if ($raw === false || !preg_match('/\s200\s/', $status)) throw new RuntimeException("HTTP check failed: $path ($status)");
        $json = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (($json['ok'] ?? null) !== true || !is_array($json['data'] ?? null)) throw new RuntimeException("Invalid API response: $path");
        return $json['data'];
    }
    throw new RuntimeException("Runtime remained busy: $path");
}
$profile = request('/api/default-profile')['profile'];
$result = request('/api/duel', ['profile' => $profile, 'armies' => ['A' => ['soldier' => 1], 'B' => ['soldier' => 1]], 'seed' => 42, 'weather' => 'neutral']);
if (($result['runtime']['kind'] ?? '') !== 'rust') throw new RuntimeException('Rust runtime unavailable');
$profiles = request('/api/profiles')['profiles'] ?? null;
if (!is_array($profiles)) throw new RuntimeException('Invalid profile listing');
foreach ($profiles as $row) {
    $loaded = request('/api/load-profile', ['id' => $row['id']]);
    if (($loaded['id'] ?? null) !== $row['id'] || !is_array($loaded['profile'] ?? null)) throw new RuntimeException('Profile load mismatch');
}
printf("HTTP=ok runtime=rust profiles=%d\n", count($profiles));
PHP
