$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

cargo fmt --check --manifest-path "$root/rust/Cargo.toml"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
cargo test --locked --manifest-path "$root/rust/Cargo.toml"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
cargo build --locked --release --manifest-path "$root/rust/Cargo.toml"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php -d ffi.enable=1 "$root/../../vendor/bin/phpunit" -c "$root/phpunit.xml"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php -d ffi.enable=1 "$root/bin/demo.php" | Out-Null
exit $LASTEXITCODE
