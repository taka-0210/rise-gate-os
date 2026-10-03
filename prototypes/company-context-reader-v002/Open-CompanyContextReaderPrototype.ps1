[CmdletBinding()]
param(
    [ValidateRange(1024, 65535)]
    [int] $Port = 41793
)

$ErrorActionPreference = 'Stop'
$prototypeRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpPath = 'C:\xampp\php\php.exe'

if (-not (Test-Path -LiteralPath $phpPath -PathType Leaf)) {
    throw 'Local PHP executable was not found. No server was started.'
}

$reviewPath = Join-Path $prototypeRoot 'review.html'
if (-not (Test-Path -LiteralPath $reviewPath -PathType Leaf)) {
    throw 'Prototype review entry was not found. No server was started.'
}

$uri = "http://127.0.0.1:$Port/review.html"
$server = $null

try {
    $server = Start-Process -FilePath $phpPath `
        -ArgumentList @('-S', "127.0.0.1:$Port", '-t', $prototypeRoot) `
        -WorkingDirectory $prototypeRoot `
        -WindowStyle Hidden `
        -PassThru

    $ready = $false
    foreach ($attempt in 1..30) {
        if ($server.HasExited) {
            throw 'The isolated prototype server stopped before becoming ready.'
        }
        try {
            $response = Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 1
            if ($response.StatusCode -eq 200) {
                $ready = $true
                break
            }
        } catch {
            Start-Sleep -Milliseconds 200
        }
    }

    if (-not $ready) {
        throw 'The isolated prototype server did not become ready.'
    }

    Start-Process $uri
    Write-Host 'Company Context Reader Prototype v002 MotionをBrowserで開きました。'
    Write-Host 'Review終了後、このPowerShellでEnterを押すとlocalhost serverを停止します。'
    [void] (Read-Host)
} finally {
    if ($null -ne $server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id
    }
}
