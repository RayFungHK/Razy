<#
.SYNOPSIS
    Benchmark Runner (PowerShell) — Runs k6 via Docker for all 6 scenarios.
.USAGE
    .\benchmark\scripts\run-all.ps1 -Target razy -Host "bench-razy:8080" -Runs 3
    .\benchmark\scripts\run-all.ps1 -Target laravel -Host "bench-laravel:8080" -Runs 3
.NOTES
    Uses Docker k6 on benchmark_default network (container-to-container).
#>
param(
    [Parameter(Mandatory)]
    [ValidateSet('razy','laravel')]
    [string]$Target,

    [Parameter(Mandatory)]
    [string]$TargetHost,

    [int]$Runs = 3,

    [int]$WarmupVUs = 20,
    [int]$WarmupDuration = 30,
    [int]$CooldownScenario = 15,
    [int]$CooldownRun = 10
)

$ErrorActionPreference = 'Continue'
$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$BenchDir   = Split-Path -Parent $ScriptDir
$K6Dir      = Join-Path $BenchDir 'k6\scenarios'
$ResultsDir = Join-Path $BenchDir "results\$Target"

$Scenarios = @(
    '01_static_route'
    '02_template_render'
    '03_db_read'
    '04_db_write'
    '05_composite'
    '06_heavy_cpu'
)

# Create results dir
New-Item -ItemType Directory -Force -Path $ResultsDir | Out-Null

Write-Host "`n========================================================"
Write-Host "  Benchmark Runner"
Write-Host "  Target:    $Target @ $TargetHost"
Write-Host "  Scenarios: $($Scenarios.Count)"
Write-Host "  Runs:      $Runs each"
Write-Host "  Results:   $ResultsDir"
Write-Host "========================================================`n"

# Pre-flight health check (via Docker network)
Write-Host "[Pre-flight] Checking $TargetHost ..."
$healthFile = Join-Path $env:TEMP "k6_health_$Target.js"
@"
import http from 'k6/http';
export default function () {
    let r = http.get('http://${TargetHost}/benchmark/static');
    if (r.status !== 200) throw new Error('Health check failed: ' + r.status);
}
"@ | Set-Content -Path $healthFile -Encoding UTF8

$dockerHealthFile = $healthFile -replace '\\','/' -replace '^C:','/c'
docker run --rm --network benchmark_default `
    -v "${dockerHealthFile}:/scripts/health.js:ro" `
    grafana/k6:latest run --quiet --no-summary --vus 1 --iterations 1 `
    /scripts/health.js 2>&1 | Out-Null

if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Health check failed for $TargetHost" -ForegroundColor Red
    exit 1
}
Write-Host "[Pre-flight] OK`n"

# Warmup
Write-Host "[Warmup] Sending requests for ${WarmupDuration}s at ${WarmupVUs} VUs ..."
$warmupScript = @"
import http from 'k6/http';
import { sleep } from 'k6';
export default function () {
    http.get('http://${TargetHost}/benchmark/static');
    sleep(0.01);
}
"@

$warmupFile = Join-Path $env:TEMP "k6_warmup_$Target.js"
$warmupScript | Set-Content -Path $warmupFile -Encoding UTF8

$dockerWarmupFile = $warmupFile -replace '\\','/' -replace '^C:','/c'
docker run --rm --network benchmark_default `
    -v "${dockerWarmupFile}:/scripts/warmup.js:ro" `
    grafana/k6:latest run --quiet --no-summary `
    --vus $WarmupVUs --duration "${WarmupDuration}s" `
    /scripts/warmup.js 2>&1 | Out-Null

Write-Host "[Warmup] Done`n"

# Run scenarios
foreach ($scenario in $Scenarios) {
    $scenarioFile = Join-Path $K6Dir "$scenario.js"
    if (-not (Test-Path $scenarioFile)) {
        Write-Host "[SKIP] $scenario - script not found" -ForegroundColor Yellow
        continue
    }

    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    Write-Host "  Scenario: $scenario"
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    for ($run = 1; $run -le $Runs; $run++) {
        $resultFile  = Join-Path $ResultsDir "${scenario}_run${run}.json"
        $logFile     = Join-Path $ResultsDir "${scenario}_run${run}.log"

        Write-Host "`n  Run $run/$Runs ..."

        # Convert Windows path to Docker-friendly path
        $dockerK6Dir = $K6Dir -replace '\\','/' -replace '^C:','/c'
        $dockerResultsDir = $ResultsDir -replace '\\','/' -replace '^C:','/c'
        # Parent of target-specific dir for handleSummary's relative path (benchmark/results/)
        $dockerParentResultsDir = (Split-Path $ResultsDir) -replace '\\','/' -replace '^C:','/c'

        # Mount:
        #   /scripts      -> scenario JS files (ro)
        #   /benchmark/results -> parent results dir (for handleSummary relative paths)
        # Set -w / so relative path benchmark/results/... resolves to /benchmark/results/...
        $output = docker run --rm --network benchmark_default -w / `
            -v "${dockerK6Dir}:/scripts:ro" `
            -v "${dockerParentResultsDir}:/benchmark/results" `
            grafana/k6:latest run `
            -e "TARGET_HOST=$TargetHost" `
            --summary-export="/benchmark/results/${Target}/${scenario}_run${run}.json" `
            "/scripts/$scenario.js" 2>&1

        $output | Set-Content -Path $logFile -Encoding UTF8
        
        # Show key metrics from output
        $output | Select-String -Pattern 'http_reqs|http_req_duration|checks' | ForEach-Object { Write-Host "    $_" }

        if (Test-Path $resultFile) {
            Write-Host "  -> Saved: ${scenario}_run${run}.json" -ForegroundColor Green
        } else {
            Write-Host "  -> WARNING: Result file not created" -ForegroundColor Yellow
        }

        # Cool-down between runs
        if ($run -lt $Runs) {
            Write-Host "  Cooling down ${CooldownRun}s ..."
            Start-Sleep -Seconds $CooldownRun
        }
    }

    # Cool-down between scenarios
    Write-Host "`n  Scenario complete. Cooling down ${CooldownScenario}s ...`n"
    Start-Sleep -Seconds $CooldownScenario
}

Write-Host "`n========================================================"
Write-Host "  All scenarios complete for: $Target"  
Write-Host "  Results in: $ResultsDir"
Write-Host "========================================================`n"
