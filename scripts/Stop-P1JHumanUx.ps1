[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$runtime = Join-Path $env:LOCALAPPDATA 'CompanyOS\P1JHumanUx'
$statePath = Join-Path $runtime 'runtime-state.json'
if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) {
    Write-Output 'P1-J runtime state was not found.'
    exit 0
}

$state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
foreach ($entry in @(@{ id = $state.node_pid; name = 'node' }, @{ id = $state.php_pid; name = 'php' })) {
    if (-not $entry.id) { continue }
    $process = Get-Process -Id $entry.id -ErrorAction SilentlyContinue
    if ($process -and $process.ProcessName -eq $entry.name) {
        Start-Process -FilePath 'taskkill.exe' -ArgumentList @('/PID', [string] $process.Id, '/T', '/F') -WindowStyle Hidden -Wait | Out-Null
    }
}
$state.state = 'STOPPED'
$state | Add-Member -NotePropertyName stopped_at_jst -NotePropertyValue ([TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTimeOffset]::UtcNow, 'Tokyo Standard Time').ToString('o')) -Force

$state | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
Write-Output 'P1-J loopback runtime stopped.'
