$ErrorActionPreference = 'Stop'
$base = Split-Path -Parent $PSScriptRoot
$configPath = Join-Path $base 'config.json'
$config = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
$ini = Join-Path $base 'php.ini'
$env:PATH = [IO.Path]::GetDirectoryName($config.php) + ';' + $env:PATH
$running = $false
try {
    $client = New-Object Net.Sockets.TcpClient
    $client.Connect('127.0.0.1', [int]$config.port)
    $running = $client.Connected
    $client.Dispose()
} catch {}
if (!$running) {
    $arguments = @('-c', ('"' + $ini + '"'), ('"' + (Join-Path $PSScriptRoot 'helper.php') + '"'), ('"' + $configPath + '"'))
    Start-Process -FilePath $config.php -ArgumentList $arguments -WorkingDirectory $base -WindowStyle Hidden -RedirectStandardOutput (Join-Path $base 'helper-out.log') -RedirectStandardError (Join-Path $base 'helper-error.log')
    Start-Sleep -Milliseconds 700
}
Start-Process ('http://127.0.0.1:' + $config.port + '/')
