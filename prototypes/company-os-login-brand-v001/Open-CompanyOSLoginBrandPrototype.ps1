$ErrorActionPreference = 'Stop'

$prototypeRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = (Resolve-Path (Join-Path $prototypeRoot '..\..')).Path
$php = 'C:\xampp\php\php.exe'
$port = 41803
$url = "http://127.0.0.1:$port/prototypes/company-os-login-brand-v001/review.html"

if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP runtime was not found: $php"
}

$server = Start-Process -FilePath $php -ArgumentList '-S', "127.0.0.1:$port", '-t', $repositoryRoot -WorkingDirectory $repositoryRoot -WindowStyle Hidden -PassThru

try {
    $ready = $false
    foreach ($attempt in 1..20) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 2
            if ($response.StatusCode -eq 200 -and $response.Content -match 'company-os-login-brand-v001-review') {
                $ready = $true
                break
            }
        } catch {
            Start-Sleep -Milliseconds 250
        }
    }
    if (-not $ready) {
        throw 'Prototype server did not become ready.'
    }
    Start-Process $url
    Write-Host ''
    Write-Host 'Company OS Login Brand Prototype is open.'
    Write-Host "URL: $url"
    Write-Host 'Press Enter to stop the local prototype server.'
    [void](Read-Host)
} finally {
    if ($server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id
    }
}
