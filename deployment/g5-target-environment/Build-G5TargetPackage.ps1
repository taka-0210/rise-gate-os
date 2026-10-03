[CmdletBinding()]
param([switch] $VerifyOnly)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$ContractId = 'company-os.ir1.g5-target-environment.v1'
$G4PackageSha = '5c99d35d03bcfd08bb83e0ad81cb90ed0cf4ef7e4eb4f363930126e7c4c9118a'
$Utf8NoBom = [Text.UTF8Encoding]::new($false)

function Stop-G5([string] $Code) { throw "G5_TARGET_PACKAGE_STOP:$Code" }
function Get-Sha([string] $Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}
function Unix([string] $Path) {
    $value = [IO.Path]::GetFullPath($Path)
    if ($value -notmatch '^([A-Za-z]):\\(.*)$') { Stop-G5 'PATH_CONVERSION_FAILED' }
    return "/$($Matches[1].ToLowerInvariant())/$($Matches[2] -replace '\\','/')"
}
function Run([string] $File, [string[]] $Arguments) {
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) { Stop-G5 'PROCESS_FAILED' }
}

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Bash = 'C:\Program Files\Git\bin\bash.exe'
$Php = Join-Path $Root 'storage\app\release-audit\g3-toolchain\php-8.3.30-Win32-vs16-x64\php.exe'
$G4Root = Join-Path $Root "storage\app\release-audit\g4-topology-package-$Candidate"
$G4Package = Join-Path $G4Root "company-os-g4-topology-ir1-$Candidate.tar.gz"
$Output = Join-Path $Root "storage\app\release-audit\g5-target-package-$Candidate"
$PackageName = "company-os-g5-target-$Candidate.tar.gz"
$Files = @(
    'G5_TARGET_PROCEDURE.md',
    'inspect-target-read-only.sh',
    'rehearse-posix-capabilities.sh',
    'target-contract.json'
)

foreach ($path in @($Bash, $Php, $G4Package)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-G5 'PREREQUISITE_MISSING' }
}
if ((Get-Sha $G4Package) -ne $G4PackageSha) { Stop-G5 'G4_PACKAGE_BINDING_MISMATCH' }

$contract = Get-Content -Raw (Join-Path $PSScriptRoot 'target-contract.json') | ConvertFrom-Json
if ($contract.contract_id -ne $ContractId -or
    $contract.source_commit -ne $Candidate -or
    $contract.g4_package_sha256 -ne $G4PackageSha -or
    $contract.production_urls.application_target -ne 'app.company-os.jp' -or
    [bool] $contract.production_mutation_authorized) {
    Stop-G5 'TARGET_CONTRACT_INVALID'
}

foreach ($file in $Files) {
    if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot $file) -PathType Leaf)) {
        Stop-G5 'PACKAGE_SOURCE_MISSING'
    }
}
foreach ($shell in @('inspect-target-read-only.sh', 'rehearse-posix-capabilities.sh')) {
    $path = Join-Path $PSScriptRoot $shell
    if ([Text.Encoding]::UTF8.GetString([IO.File]::ReadAllBytes($path)).Contains([char] 13)) {
        Stop-G5 'SHELL_NOT_LF_ONLY'
    }
    Run $Bash @('--noprofile', '--norc', '-n', (Unix $path))
}
$simulation = @(& $Php (Join-Path $PSScriptRoot 'simulate-g5-boundaries.php'))
if ($LASTEXITCODE -ne 0 -or $simulation -notcontains 'G5_BOUNDARY_SIMULATION=PASS') {
    Stop-G5 'BOUNDARY_SIMULATION_FAILED'
}

if ($VerifyOnly) {
    Write-Output 'G5_TARGET_PACKAGE_VERIFY_ONLY=PASS'
    Write-Output "candidate=$Candidate"
    Write-Output 'boundary_simulation=PASS'
    Write-Output 'production_connection_attempted=false'
    Write-Output 'production_mutation=false'
    exit 0
}
if (Test-Path -LiteralPath $Output) { Stop-G5 'OUTPUT_ALREADY_EXISTS' }
New-Item -ItemType Directory -Path $Output | Out-Null
$Temp = Join-Path ([IO.Path]::GetTempPath()) "company-os-g5-$PID"
$Stage = Join-Path $Temp 'package'
$Extract = Join-Path $Temp 'extract'
New-Item -ItemType Directory -Path $Stage, $Extract | Out-Null
try {
    foreach ($file in $Files) {
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination (Join-Path $Stage $file)
    }
    $entries = foreach ($file in ($Files | Sort-Object)) {
        $path = Join-Path $Stage $file
        [ordered]@{ path=$file; bytes=(Get-Item $path).Length; sha256=Get-Sha $path }
    }
    $manifest = [ordered]@{
        schema_version=1
        contract_id=$ContractId
        source_commit=$Candidate
        g4_package_sha256=$G4PackageSha
        operation_set=@('read_only_inspection','isolated_capability_rehearsal')
        files=@($entries)
    }
    $manifestPath = Join-Path $Stage 'package-manifest.json'
    [IO.File]::WriteAllText($manifestPath, (($manifest | ConvertTo-Json -Depth 6)+[char]10), $Utf8NoBom)
    $checks = foreach ($file in (($Files + 'package-manifest.json') | Sort-Object)) {
        "$(Get-Sha (Join-Path $Stage $file))  $file"
    }
    [IO.File]::WriteAllText((Join-Path $Stage 'checksums.sha256'), (($checks -join [char]10)+[char]10), [Text.Encoding]::ASCII)

    $one = Join-Path $Output $PackageName
    $two = Join-Path $Temp 'second.tar.gz'
    foreach ($archive in @($one,$two)) {
        Run $Bash @('--noprofile','--norc','-c',
            "tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' -C '$(Unix $Stage)' -czf '$(Unix $archive)' .")
    }
    $packageSha = Get-Sha $one
    if ($packageSha -ne (Get-Sha $two)) { Stop-G5 'DETERMINISTIC_BUILD_MISMATCH' }
    Run $Bash @('--noprofile','--norc','-c',
        "tar -xzf '$(Unix $one)' -C '$(Unix $Extract)' && cd '$(Unix $Extract)' && sha256sum -c checksums.sha256 >/dev/null")
    "$packageSha  $PackageName" | Set-Content -LiteralPath (Join-Path $Output "$PackageName.sha256") -Encoding ascii
    Copy-Item $manifestPath (Join-Path $Output 'package-manifest.json')
    Copy-Item (Join-Path $Stage 'checksums.sha256') (Join-Path $Output 'checksums.sha256')
    $evidence = [ordered]@{
        schema='company-os.ir1.g5-target-package.evidence.v1'
        status='PASS'
        candidate=$Candidate
        package=[ordered]@{ file=$PackageName; sha256=$packageSha; bytes=(Get-Item $one).Length; deterministic=$true }
        verification=[ordered]@{ shell_parse='PASS'; archive_integrity='PASS'; boundary_scenarios=7; boundary_assertions=15 }
        production=[ordered]@{ connection_attempted=$false; mutation=$false }
        continuing_blockers=@($contract.continuing_blockers)
    }
    $evidencePath=Join-Path $Output 'g5-target-package-evidence.json'
    [IO.File]::WriteAllText($evidencePath,(($evidence|ConvertTo-Json -Depth 7)+[char]10),$Utf8NoBom)
    Get-Sha $evidencePath | Set-Content -LiteralPath "$evidencePath.sha256" -Encoding ascii
    Write-Output 'G5_TARGET_PACKAGE=PASS'
    Write-Output "package_sha256=$packageSha"
    Write-Output 'deterministic_rebuild=true'
    Write-Output 'production_connection_attempted=false'
    Write-Output 'production_mutation=false'
} finally {
    if (Test-Path -LiteralPath $Temp) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}
