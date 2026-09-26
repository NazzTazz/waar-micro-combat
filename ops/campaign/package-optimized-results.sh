#!/usr/bin/env bash
set -euo pipefail

root=/home/debian/waar-campaign-optimized
archive_dir="$root/exports"
name=waar-campaign-optimized-results-2026-09-26.tar.gz
partial="$archive_dir/$name.partial"
final="$archive_dir/$name"

mkdir -p "$archive_dir"
if [[ -e "$partial" || -e "$final" ]]; then
    echo "Archive already exists; refusing to overwrite: $name" >&2
    exit 1
fi

source_files=$(find "$root/results" -type f | wc -l)
echo "Packaging $source_files files from $root/results"
nice -n 10 ionice -c2 -n7 tar -C "$root" -cf - results | pigz -1 -p 2 > "$partial"
echo 'Checking archive structure'
tar -tzf "$partial" > /dev/null
archive_files=$(tar -tzf "$partial" | grep -vc '/$')
if [[ "$archive_files" -ne "$source_files" ]]; then
    echo "File count mismatch: source=$source_files archive=$archive_files" >&2
    exit 1
fi

mv -- "$partial" "$final"
sha256sum "$final" > "$final.sha256"
ls -lh "$final" "$final.sha256"
cat "$final.sha256"
