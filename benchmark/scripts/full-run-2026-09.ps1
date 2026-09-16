# Full symmetric benchmark run — 2026-09 epoch.
# STRICTLY SERIAL (parallel runs would fight for the 2-CPU containers and
# corrupt every number). Four passes: worker Razy, worker Laravel, fpm Razy,
# fpm Laravel — 6 scenarios x 3 runs each, k6 pinned 2.0.0 via Docker network.
#
# Prereq (all must be up before launch):
#   docker compose up -d                              # mysql + razy
#   docker compose --profile laravel up -d            # laravel worker
#   docker compose --profile fpm up -d                # both fpm + caddy front
#
# Raw k6 JSON lands in benchmark/results/<target>/ — a result without its
# raw JSON is not a result.

$ErrorActionPreference = 'Continue'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location (Split-Path -Parent $ScriptDir)   # -> benchmark/

function Invoke-Pass {
    param([string]$Name, [string]$Host_)
    Write-Host "`n########## PASS: $Name ($Host_) ##########`n"
    & powershell -ExecutionPolicy Bypass -File (Join-Path $ScriptDir 'run-all.ps1') `
        -Target $Name -TargetHost $Host_ -Runs 3
    Write-Host "`n########## PASS DONE: $Name (exit $LASTEXITCODE) ##########`n"
    Start-Sleep -Seconds 60   # inter-stack cooldown (MySQL drains, page cache settles)
}

# Toolchain receipts into the results root (pinned-toolchain rule: no receipts, no run)
$receipts = Join-Path (Get-Location) 'results\toolchain.txt'
"epoch=2026-09`nk6=grafana/k6:2.0.0`n" | Set-Content $receipts
foreach ($c in @('bench-razy','bench-laravel','bench-razy-fpm','bench-laravel-fpm','bench-mysql')) {
    "== $c ==" | Add-Content $receipts
    docker exec $c cat /toolchain.txt 2>$null | Add-Content $receipts
    docker exec $c php -r "echo 'pdo='.(extension_loaded('pdo_mysql')?'mysql':'?').PHP_EOL;" 2>$null | Add-Content $receipts
}
"mysql_version=$(docker exec bench-mysql mysqladmin version --version 2>$null)" | Add-Content $receipts

Invoke-Pass -Name 'razy'        -Host_ 'bench-razy:8080'
Invoke-Pass -Name 'laravel'     -Host_ 'bench-laravel:8080'
Invoke-Pass -Name 'razy-fpm'    -Host_ 'bench-fpm-caddy:8083'
Invoke-Pass -Name 'laravel-fpm' -Host_ 'bench-fpm-caddy:8084'

Write-Host "`nFULL RUN COMPLETE — receipts: $receipts"
