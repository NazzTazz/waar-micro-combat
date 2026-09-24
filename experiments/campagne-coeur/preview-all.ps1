# Run from any directory, after installing this folder as experiments/campagne-coeur.
# Configuration validation only. No simulations are requested.
$ErrorActionPreference = 'Stop'
$campaignRoot = $PSScriptRoot
$repoRoot = Split-Path (Split-Path $campaignRoot -Parent) -Parent
$manifest = Get-Content (Join-Path $campaignRoot 'campaign-manifest.json') -Raw | ConvertFrom-Json
$profilePath = Join-Path $repoRoot 'reports/campaign-manual-sol/profile.json'
if (-not (Test-Path $profilePath)) { throw "Missing profile: $profilePath" }
$actualHash = (Get-FileHash $profilePath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actualHash -ne $manifest.profile.sha256) { throw 'Authentic profile differs from the supplied reference. Stop; do not overwrite it.' }
Push-Location $repoRoot
try {
    $plans = Get-ChildItem -LiteralPath $campaignRoot -Filter '*.json' -File |
        Where-Object Name -NotIn @('reference-profile.json', 'campaign-design.json', 'campaign-manifest.json') |
        Sort-Object Name
    foreach ($plan in $plans) {
        $preview = & php 'bin/parametric-campaign.php' preview $plan.FullName | ConvertFrom-Json
        if ($LASTEXITCODE -ne 0) { throw "Preview rejected: $($plan.Name)" }
        Write-Host "$($plan.Name): $($preview.experiments) configurations, $($preview.combats) combats prévus"
    }
    & php (Join-Path $campaignRoot 'split-large-plans.php')
    if ($LASTEXITCODE -ne 0) { throw 'Plan partitioning failed.' }
    foreach ($plan in (Get-ChildItem (Join-Path $campaignRoot 'segments') -Filter '*.json' -File | Where-Object Name -ne 'segments-manifest.json')) {
        $preview = & php 'bin/parametric-campaign.php' preview $plan.FullName | ConvertFrom-Json
        if ($LASTEXITCODE -ne 0) { throw "Segment rejected: $($plan.Name)" }
    }
    & php (Join-Path $campaignRoot 'prepare-preview.php')
    if ($LASTEXITCODE -ne 0) { throw 'Preparation report contains rejected plans.' }
} finally { Pop-Location }
