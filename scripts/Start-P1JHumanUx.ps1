[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = 'C:\xampp\php\php.exe'
$openssl = 'C:\xampp\apache\bin\openssl.exe'
$node = Join-Path $env:LOCALAPPDATA 'CompanyOS\ProviderEvaluation\CE-PD08B-E0-E2\runtime\node-v24.21.0-win-x64\node.exe'
$evalRoot = Join-Path $env:LOCALAPPDATA 'CompanyOS\ProviderEvaluation\CE-PD08B-E0-E2'
$credential = Join-Path $evalRoot 'deepgram-api-key.dpapi'
$dpapiHelper = Join-Path $evalRoot 'harness\scripts\Read-DpapiSecret.ps1'
$runtime = Join-Path $env:LOCALAPPDATA 'CompanyOS\P1JHumanUx'
$statePath = Join-Path $runtime 'runtime-state.json'
$certPath = Join-Path $runtime 'localhost.crt'
$keyPath = Join-Path $runtime 'localhost.key'
$evidencePath = Join-Path $runtime 'p1-j-evidence.jsonl'
$databasePath = Join-Path $runtime 'p1-j.sqlite'

foreach ($required in @($php, $openssl, $node, $credential, $dpapiHelper)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw "Required P1-J runtime asset is missing: $required" }
}
New-Item -ItemType Directory -Path $runtime -Force | Out-Null

if (Test-Path -LiteralPath $statePath) {
    $prior = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $running = @()
    foreach ($processId in @($prior.php_pid, $prior.node_pid)) {
        if ($processId -and (Get-Process -Id $processId -ErrorAction SilentlyContinue)) { $running += $processId }
    }
    if ($running.Count -gt 0) { throw 'A P1-J runtime is already active. Stop it before starting another.' }
}

Copy-Item -LiteralPath (Join-Path $repo 'database\\database.sqlite') -Destination $databasePath -Force

$env:OPENSSL_CONF = 'C:\xampp\apache\conf\openssl.cnf'
if (-not (Test-Path -LiteralPath $certPath) -or -not (Test-Path -LiteralPath $keyPath)) {
    $savedErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 7 `
        -keyout $keyPath -out $certPath -subj '/CN=localhost' `
        -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' 2>$null
    $opensslExitCode = $LASTEXITCODE
    $ErrorActionPreference = $savedErrorActionPreference
    if ($opensslExitCode -ne 0) { throw 'Temporary localhost certificate generation failed.' }
}

$tokenBytes = New-Object byte[] 32
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($tokenBytes)
$rng.Dispose()
$bridgeToken = [BitConverter]::ToString($tokenBytes).Replace('-', '').ToLowerInvariant()

$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $databasePath
& $php artisan migrate --force --no-ansi | Out-File -LiteralPath (Join-Path $runtime 'migration.log') -Encoding utf8
if ($LASTEXITCODE -ne 0) { throw 'Isolated P1-J database migration failed.' }

$env:APP_URL = 'https://localhost:8443'
$env:COMPANY_OS_TRUST_LOOPBACK_PROXY = 'true'
$env:COMPANY_OS_REALTIME_ENABLED = 'true'
$env:COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED = 'true'
$env:COMPANY_OS_REALTIME_RELAY_URL = 'wss://localhost:8443/realtime-relay'
$env:COMPANY_OS_REALTIME_BRIDGE_TOKEN = $bridgeToken
$env:COMPANY_OS_REALTIME_LEASE_TTL_SECONDS = '12'
$env:COMPANY_OS_REALTIME_LEASE_REFRESH_SECONDS = '4'
$env:COMPANY_OS_P1_J_APPROVED = 'true'
$env:COMPANY_OS_REALTIME_RETRY_MAX = '0'
$env:COMPANY_OS_REALTIME_RECONNECT_MAX = '0'
$env:COMPANY_OS_REALTIME_MAX_PROVIDER_SESSIONS = '3'
$env:COMPANY_OS_REALTIME_MAX_AUDIO_SECONDS = '300'
$env:COMPANY_OS_REALTIME_COST_LIMIT_USD = '0.05'
$env:COMPANY_OS_REALTIME_RELAY_HOST = '127.0.0.1'
$env:COMPANY_OS_REALTIME_RELAY_PORT = '8443'
$env:COMPANY_OS_REALTIME_UPSTREAM_ORIGIN = 'http://127.0.0.1:8765'
$env:COMPANY_OS_REALTIME_ALLOWED_ORIGIN = 'https://localhost:8443'
$env:COMPANY_OS_REALTIME_CREDENTIAL_PATH = $credential
$env:COMPANY_OS_REALTIME_DPAPI_HELPER_PATH = $dpapiHelper
$env:COMPANY_OS_REALTIME_TLS_KEY_PATH = $keyPath
$env:COMPANY_OS_REALTIME_TLS_CERT_PATH = $certPath
$env:COMPANY_OS_P1_J_EVIDENCE_PATH = $evidencePath

$phpOut = Join-Path $runtime 'php.stdout.log'
$phpErr = Join-Path $runtime 'php.stderr.log'
$nodeOut = Join-Path $runtime 'node.stdout.log'
$nodeErr = Join-Path $runtime 'node.stderr.log'
foreach ($log in @($phpOut, $phpErr, $nodeOut, $nodeErr, $evidencePath)) { Set-Content -LiteralPath $log -Value '' -Encoding utf8 }

$nodeProcess = $null
$phpProcess = Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8765') `
    -WorkingDirectory $repo -WindowStyle Hidden -PassThru -RedirectStandardOutput $phpOut -RedirectStandardError $phpErr
try {
    $phpReady = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ($phpProcess.HasExited) { throw "Laravel runtime exited early. See $phpErr" }
        if (Test-NetConnection -ComputerName 127.0.0.1 -Port 8765 -InformationLevel Quiet -WarningAction SilentlyContinue) { $phpReady = $true; break }
        Start-Sleep -Milliseconds 250
    }
    if (-not $phpReady) { throw 'Laravel loopback runtime did not become ready.' }

    $nodeProcess = Start-Process -FilePath $node -ArgumentList @('realtime-relay/src/product-relay.js') `
        -WorkingDirectory $repo -WindowStyle Hidden -PassThru -RedirectStandardOutput $nodeOut -RedirectStandardError $nodeErr
    $nodeReady = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ($nodeProcess.HasExited) { throw "Relay runtime exited early. See $nodeErr" }
        if ((Get-Content -LiteralPath $nodeOut -Raw -ErrorAction SilentlyContinue) -match '"status":"READY"') { $nodeReady = $true; break }
        Start-Sleep -Milliseconds 250
    }
    if (-not $nodeReady) { throw 'Relay loopback runtime did not become ready.' }

    [ordered]@{
        schema_version = 1
        state = 'READY_FOR_HUMAN_MICROPHONE_GATE'
        started_at_jst = [TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTimeOffset]::UtcNow, 'Tokyo Standard Time').ToString('o')
        url = 'https://localhost:8443/company/co'
        php_pid = $phpProcess.Id
        node_pid = $nodeProcess.Id
        provider_requests = 0
        audio_seconds = 0
        evidence_path = $evidencePath
        database_mode = 'isolated_copy'
        database_path = $databasePath
    } | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
    Get-Content -LiteralPath $statePath -Raw
} catch {
    if ($nodeProcess -and -not $nodeProcess.HasExited) { Stop-Process -Id $nodeProcess.Id -Force }
    if (-not $phpProcess.HasExited) { Stop-Process -Id $phpProcess.Id -Force }
    throw
}
