#!/usr/bin/env bash
set -euo pipefail

name=waar-campaign-optimized-results-2026-09-26.tar.gz
source=/home/debian/waar-campaign-optimized/exports/$name
download_root=/var/lib/docker/volumes/wai-caddy-data/_data/downloads
target_dir=$download_root/campaign
target=$target_dir/$name
config=/opt/gateway/caddy/Caddyfile
candidate=/opt/gateway/caddy/Caddyfile.campaign.candidate
container=wai-gateway-caddy-1

test -s "$source"
sudo -n install -d -m 755 "$target_dir"
if sudo -n test -e "$target"; then
    sudo -n cmp -s -- "$source" "$target"
else
    sudo -n cp --reflink=auto -- "$source" "$target.partial"
    sudo -n cmp -s -- "$source" "$target.partial"
    sudo -n chmod 644 "$target.partial"
    sudo -n mv -- "$target.partial" "$target"
fi
sudo -n sha256sum "$target" | sed "s|  $target|  $name|" | sudo -n tee "$target.sha256" > /dev/null
sudo -n chmod 644 "$target.sha256"

sudo -n python3 - "$config" "$candidate" <<'PY'
import pathlib
import sys

source = pathlib.Path(sys.argv[1])
target = pathlib.Path(sys.argv[2])
old = '''  reverse_proxy waar-engine-demo:8080 {
    header_up -Authorization
  }
'''
new = '''  handle /campaign/* {
    root * /data/downloads
    header Content-Disposition attachment
    file_server
  }
  handle {
    reverse_proxy waar-engine-demo:8080 {
      header_up -Authorization
    }
  }
'''
config = source.read_text()
if config.count(old) != 1 or 'handle /campaign/*' in config:
    raise SystemExit('Unexpected Caddy configuration; refusing to modify it')
target.write_text(config.replace(old, new, 1))
PY

sudo -n docker cp "$candidate" "$container:/tmp/Caddyfile.campaign.candidate"
sudo -n docker exec "$container" caddy validate --config /tmp/Caddyfile.campaign.candidate --adapter caddyfile
backup="$config.pre-campaign-$(date -u +%Y%m%dT%H%M%SZ)"
sudo -n cp -p -- "$config" "$backup"
sudo -n install -m 644 -- "$candidate" "$config"
if ! sudo -n docker compose -f /opt/gateway/caddy/compose.yaml --project-directory /opt/gateway/caddy up -d --no-deps --force-recreate caddy; then
    sudo -n cp -p -- "$backup" "$config"
    sudo -n docker compose -f /opt/gateway/caddy/compose.yaml --project-directory /opt/gateway/caddy up -d --no-deps --force-recreate caddy
    echo 'Caddy recreation failed; original configuration restored' >&2
    exit 1
fi
sudo -n docker exec "$container" grep -q '/data/downloads' /etc/caddy/Caddyfile

echo "Archive: $target"
echo "Checksum: $target.sha256"
echo "Config backup: $backup"
