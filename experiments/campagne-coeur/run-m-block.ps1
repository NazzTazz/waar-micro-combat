param(
    [string]$Repository = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path,
    [ValidateSet('pc', 'all')][string]$Worker = 'pc'
)

$ErrorActionPreference = 'Stop'
$plansRoot = Join-Path $Repository 'experiments/campagne-coeur'
$index = Join-Path $Repository 'reports/campagne-coeur/preparation/active-plans.csv'
$logRoot = Join-Path $Repository 'reports/campagne-coeur/execution-m'
New-Item -ItemType Directory -Path $logRoot -Force | Out-Null

$rows = @(Import-Csv $index | Where-Object { $_.phase -eq 'M' })
if ($rows.Count -ne 63) { throw "Expected 63 active M plans, found $($rows.Count)." }
$expectedCombats = ($rows | Measure-Object -Property combats -Sum).Sum
if ($expectedCombats -ne 15876000) { throw "Unexpected M combat count: $expectedCombats." }
if ($Worker -eq 'pc') {
    $rows = @($rows | Where-Object { $_.plan -notmatch '^M-unit-(knight|soldier|spearman)-' })
    if ($rows.Count -ne 36) { throw "Expected 36 PC plans, found $($rows.Count)." }
    $expectedCombats = ($rows | Measure-Object -Property combats -Sum).Sum
    if ($expectedCombats -ne 8340000) { throw "Unexpected PC combat count: $expectedCombats." }
}

foreach ($row in $rows) {
    $planPath = Join-Path $plansRoot $row.plan
    $actualHash = (Get-FileHash -LiteralPath $planPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $row.planSha256) { throw "Plan changed: $($row.plan)" }
    $plan = Get-Content -LiteralPath $planPath -Raw | ConvertFrom-Json
    $outputPath = [System.IO.Path]::GetFullPath((Join-Path (Split-Path $planPath -Parent) $plan.output))
    $manifestPath = Join-Path $outputPath 'manifest.json'
    $label = [System.IO.Path]::GetFileNameWithoutExtension($row.plan)
    $runLog = Join-Path $logRoot "$label.run.log"
    $exportLog = Join-Path $logRoot "$label.export.log"

    Write-Host "[$(Get-Date -Format o)] resume $($row.plan) ($($row.combats) combats)"
    $ErrorActionPreference = 'Continue'
    & php (Join-Path $Repository 'bin/parametric-campaign.php') resume $planPath *> $runLog
    $ErrorActionPreference = 'Stop'
    if ($LASTEXITCODE -ne 0) { throw "Run failed: $($row.plan); see $runLog" }
    if (-not (Test-Path -LiteralPath $manifestPath)) { throw "Missing manifest: $manifestPath" }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    if ($manifest.status -ne 'complete' -or [long]$manifest.completedCombats -ne [long]$row.combats -or @($manifest.errors).Count -ne 0) {
        throw "Incomplete run: $($row.plan); see $manifestPath"
    }

    $ErrorActionPreference = 'Continue'
    & php (Join-Path $Repository 'bin/parametric-campaign.php') export $planPath *> $exportLog
    $ErrorActionPreference = 'Stop'
    if ($LASTEXITCODE -ne 0) { throw "Export failed: $($row.plan); see $exportLog" }
    Write-Host "[$(Get-Date -Format o)] complete $($row.plan)"
}

Write-Host "[$(Get-Date -Format o)] M $Worker shard complete: $($rows.Count) plans, $expectedCombats combats."
