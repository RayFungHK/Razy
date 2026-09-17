$ErrorActionPreference = 'Continue'
$bash = 'C:\Program Files\Git\bin\bash.exe'

foreach ($scale in 5, 25, 50) {
    Write-Output "=== SCALE=$scale ==="
    Set-Location $PSScriptRoot\..
    cmd /c "docker compose build --build-arg SCALE=$scale scale 2>&1" | Select-Object -Last 1
    # strip CRLF for the sh script (git checkout may carry CRLF in the worktree)
    (Get-Content gate\boot-curve.sh -Raw) -replace "`r", '' | Set-Content -NoNewline "$env:TEMP\boot-curve.sh" -Encoding ASCII
    cmd /c "`"$bash`" -c ""cd /c/Users/RayFung/VSCode-Projects/Razy/benchmark/gate && sh '$($env:TEMP -replace '\\','/')/boot-curve.sh' $scale 3 2>&1"""
}
Write-Output 'ALL SCALES DONE'
