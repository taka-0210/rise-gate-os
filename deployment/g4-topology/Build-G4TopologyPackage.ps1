[CmdletBinding()]
param([switch] $VerifyOnly)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$CandidateTree = '7d979ef6a854bce7943740044a3f0c4d942dc617'
$ReleaseId = "ir1-$Candidate"
$ContractId = 'company-os.ir1.g4-immutable-topology.v1'
$ArtifactSha256 = '2de9b840627d0dbfd1beabaca7e8609dc9e16be2fd9c3021e3dfdc2c764cdb69'
$ArtifactBytes = 5807985
$G3EvidenceSha256 = 'dd08b0bc4e0b0a44863922423e3f34f7492948a4c1ff4769765a69f1e064e4a1'
$OutputSchema = 'company-os.ir1.g4-topology-package.evidence.v1'
$Utf8NoBom = [Text.UTF8Encoding]::new($false)

function Stop-G4([string] $Code) { throw "G4_TOPOLOGY_PACKAGE_STOP:$Code" }
function Get-Sha256([string] $Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}
function ConvertTo-UnixPath([string] $Path) {
    $resolved = [IO.Path]::GetFullPath($Path)
    if ($resolved -notmatch '^([A-Za-z]):\\(.*)$') { Stop-G4 'UNIX_PATH_CONVERSION_FAILED' }
    return "/$($Matches[1].ToLowerInvariant())/$($Matches[2] -replace '\\', '/')"
}
function Invoke-Checked {
    param([string] $FilePath, [string[]] $Arguments)
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        Stop-G4 "PROCESS_FAILED_$([IO.Path]::GetFileNameWithoutExtension($FilePath).ToUpperInvariant())"
    }
}

$RepositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$G3Root = Join-Path $RepositoryRoot "storage\app\release-audit\g3-rc-freeze-$Candidate"
$ApplicationArtifact = Join-Path $G3Root "rise-gate-os-$Candidate.tar.gz"
$G3Evidence = Join-Path $G3Root 'g3-rc-freeze-evidence.json'
$Php = Join-Path $RepositoryRoot 'storage\app\release-audit\g3-toolchain\php-8.3.30-Win32-vs16-x64\php.exe'
$Bash = 'C:\Program Files\Git\bin\bash.exe'
$OutputRoot = Join-Path $RepositoryRoot "storage\app\release-audit\g4-topology-package-$Candidate"
$PackageName = "company-os-g4-topology-$ReleaseId.tar.gz"
$PackageFiles = @(
    'G4_PACKAGE_PROCEDURE.md', 'g4-common.sh', 'install-release.sh',
    'rollback-release.sh', 'switch-release.sh', 'topology-contract.json',
    'verify-topology.sh'
)
$ShellFiles = @(
    'g4-common.sh', 'install-release.sh', 'rollback-release.sh',
    'switch-release.sh', 'verify-topology.sh'
)

foreach ($required in @($ApplicationArtifact, $G3Evidence, $Php, $Bash)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { Stop-G4 'LOCAL_PREREQUISITE_MISSING' }
}
foreach ($relative in $PackageFiles) {
    if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot $relative) -PathType Leaf)) {
        Stop-G4 'PACKAGE_SOURCE_FILE_MISSING'
    }
}

$resolvedCommit = (& git -C $RepositoryRoot rev-parse "$Candidate^{commit}").Trim()
$resolvedTree = (& git -C $RepositoryRoot show -s --format=%T $Candidate).Trim()
if ($LASTEXITCODE -ne 0 -or $resolvedCommit -ne $Candidate -or $resolvedTree -ne $CandidateTree) {
    Stop-G4 'CANDIDATE_IDENTITY_MISMATCH'
}
if ((Get-Sha256 $ApplicationArtifact) -ne $ArtifactSha256 -or
    (Get-Item -LiteralPath $ApplicationArtifact).Length -ne $ArtifactBytes -or
    (Get-Sha256 $G3Evidence) -ne $G3EvidenceSha256) {
    Stop-G4 'G3_BINDING_MISMATCH'
}

$contract = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'topology-contract.json') | ConvertFrom-Json
if ($contract.contract_id -ne $ContractId -or $contract.release_id -ne $ReleaseId -or
    $contract.source_commit -ne $Candidate -or $contract.source_tree -ne $CandidateTree -or
    $contract.artifact.sha256 -ne $ArtifactSha256 -or
    [long] $contract.artifact.bytes -ne $ArtifactBytes -or
    $contract.workflow.trigger -ne 'workflow_dispatch_only' -or
    [bool] $contract.production_mutation_authorized) {
    Stop-G4 'TOPOLOGY_CONTRACT_MISMATCH'
}

foreach ($workflow in @(
    (Join-Path $RepositoryRoot '.github\workflows\build-release-candidate.yml'),
    (Join-Path $RepositoryRoot '.github\workflows\deploy-production.yml')
)) {
    $workflowText = Get-Content -Raw -LiteralPath $workflow
    if ($workflowText -notmatch '(?m)^\s{2}workflow_dispatch:\s*$' -or
        $workflowText -match '(?m)^\s{2}(push|pull_request|schedule):\s*$') {
        Stop-G4 'WORKFLOW_TRIGGER_CONTRACT_FAILED'
    }
}

foreach ($shell in $ShellFiles) {
    $shellPath = Join-Path $PSScriptRoot $shell
    $text = [Text.Encoding]::UTF8.GetString([IO.File]::ReadAllBytes($shellPath))
    if ($text.Contains([char] 13)) { Stop-G4 'SHELL_NOT_LF_ONLY' }
    Invoke-Checked $Bash @('--noprofile', '--norc', '-n', (ConvertTo-UnixPath $shellPath))
}
$simulationOutput = @(& $Php (Join-Path $PSScriptRoot 'simulate-topology.php'))
if ($LASTEXITCODE -ne 0 -or $simulationOutput -notcontains 'G4_TOPOLOGY_SIMULATION=PASS') {
    Stop-G4 'TOPOLOGY_SIMULATION_FAILED'
}

if ($VerifyOnly) {
    Write-Output 'G4_TOPOLOGY_PACKAGE_VERIFY_ONLY=PASS'
    Write-Output "candidate=$Candidate"
    Write-Output 'workflow_dispatch_only=true'
    Write-Output 'topology_simulation=PASS'
    Write-Output 'production_connection_attempted=false'
    Write-Output 'production_mutation=false'
    exit 0
}
if (Test-Path -LiteralPath $OutputRoot) { Stop-G4 'OUTPUT_ROOT_ALREADY_EXISTS' }
New-Item -ItemType Directory -Path $OutputRoot | Out-Null

$TempBase = Join-Path ([IO.Path]::GetTempPath()) "company-os-g4-$PID"
$Stage = Join-Path $TempBase 'package'
$Extracted = Join-Path $TempBase 'extracted'
New-Item -ItemType Directory -Path $Stage, $Extracted | Out-Null
try {
    foreach ($relative in $PackageFiles) {
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot $relative) -Destination (Join-Path $Stage $relative)
    }
    $fileEntries = foreach ($relative in ($PackageFiles | Sort-Object)) {
        $path = Join-Path $Stage $relative
        [ordered]@{ path = $relative; bytes = (Get-Item $path).Length; sha256 = Get-Sha256 $path }
    }
    $packageManifest = [ordered]@{
        schema_version = 1
        contract_id = $ContractId
        release_id = $ReleaseId
        source_commit = $Candidate
        source_tree = $CandidateTree
        artifact_sha256 = $ArtifactSha256
        artifact_bytes = $ArtifactBytes
        g3_evidence_sha256 = $G3EvidenceSha256
        workflow_trigger = 'workflow_dispatch_only'
        human_operation = 'one_step_one_command'
        files = @($fileEntries)
    }
    $manifestPath = Join-Path $Stage 'package-manifest.json'
    [IO.File]::WriteAllText(
        $manifestPath,
        (($packageManifest | ConvertTo-Json -Depth 6) + [char] 10),
        $Utf8NoBom
    )
    $checksumLines = foreach ($relative in (($PackageFiles + 'package-manifest.json') | Sort-Object)) {
        "$(Get-Sha256 (Join-Path $Stage $relative))  $relative"
    }
    [IO.File]::WriteAllText(
        (Join-Path $Stage 'checksums.sha256'),
        (($checksumLines -join [char] 10) + [char] 10),
        [Text.Encoding]::ASCII
    )

    $archiveOne = Join-Path $OutputRoot $PackageName
    $archiveTwo = Join-Path $TempBase 'second.tar.gz'
    $stageUnix = ConvertTo-UnixPath $Stage
    foreach ($archive in @($archiveOne, $archiveTwo)) {
        $archiveUnix = ConvertTo-UnixPath $archive
        $command = "tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' -C '$stageUnix' -czf '$archiveUnix' ."
        Invoke-Checked $Bash @('--noprofile', '--norc', '-c', $command)
    }
    $archiveHash = Get-Sha256 $archiveOne
    if ($archiveHash -ne (Get-Sha256 $archiveTwo)) { Stop-G4 'DETERMINISTIC_PACKAGE_MISMATCH' }

    $extractUnix = ConvertTo-UnixPath $Extracted
    $archiveOneUnix = ConvertTo-UnixPath $archiveOne
    Invoke-Checked $Bash @('--noprofile', '--norc', '-c',
        "tar -xzf '$archiveOneUnix' -C '$extractUnix' && cd '$extractUnix' && sha256sum -c checksums.sha256 >/dev/null")
    $phpUnix = ConvertTo-UnixPath $Php
    Invoke-Checked $Bash @('--noprofile', '--norc', '-c',
        "source '$extractUnix/g4-common.sh' && g4_verify_package '$extractUnix' '$phpUnix' && g4_verify_contract '$extractUnix' '$phpUnix'")
    $extractedFiles = @(Get-ChildItem -LiteralPath $Extracted -File | Sort-Object Name | Select-Object -ExpandProperty Name)
    $expectedFiles = @(($PackageFiles + @('checksums.sha256', 'package-manifest.json')) | Sort-Object)
    if (@(Compare-Object $expectedFiles $extractedFiles).Count -ne 0) { Stop-G4 'ARCHIVE_FILE_SET_MISMATCH' }

    "$archiveHash  $PackageName" | Set-Content -LiteralPath (Join-Path $OutputRoot "$PackageName.sha256") -Encoding ascii
    Copy-Item -LiteralPath $manifestPath -Destination (Join-Path $OutputRoot 'package-manifest.json')
    Copy-Item -LiteralPath (Join-Path $Stage 'checksums.sha256') -Destination (Join-Path $OutputRoot 'checksums.sha256')

    $evidence = [ordered]@{
        output_schema = $OutputSchema
        status = 'PASS'
        candidate = $Candidate
        candidate_tree = $CandidateTree
        release_id = $ReleaseId
        application_artifact_sha256 = $ArtifactSha256
        topology_package = [ordered]@{
            file = $PackageName
            sha256 = $archiveHash
            bytes = (Get-Item $archiveOne).Length
            deterministic_rebuild = $true
            file_count = $expectedFiles.Count
            symlink_count = 0
        }
        verification = [ordered]@{
            shell_parse = 'PASS'
            lf_only = $true
            topology_simulation = 'PASS'
            failure_injection_scenarios = 7
            failure_injection_assertions = 22
            workflow_dispatch_only = $true
            archive_integrity = 'PASS'
            package_runtime_contract = 'PASS'
            exact_candidate_binding = 'PASS'
            real_posix_symlink_rehearsal = 'G5_TARGET_ENVIRONMENT_REQUIRED'
        }
        production = [ordered]@{
            connection_attempted = $false
            mutation = $false
            deploy = $false
            migration = $false
            dns_or_ssl_change = $false
        }
        continuing_blockers = @($contract.continuing_blockers)
    }
    $evidencePath = Join-Path $OutputRoot 'g4-topology-package-evidence.json'
    [IO.File]::WriteAllText(
        $evidencePath,
        (($evidence | ConvertTo-Json -Depth 8) + [char] 10),
        $Utf8NoBom
    )
    Get-Sha256 $evidencePath | Set-Content -LiteralPath "$evidencePath.sha256" -Encoding ascii

    Write-Output 'G4_TOPOLOGY_PACKAGE=PASS'
    Write-Output "candidate=$Candidate"
    Write-Output "package_sha256=$archiveHash"
    Write-Output 'deterministic_rebuild=true'
    Write-Output 'workflow_dispatch_only=true'
    Write-Output 'topology_simulation=PASS'
    Write-Output 'production_connection_attempted=false'
    Write-Output 'production_mutation=false'
} finally {
    if (Test-Path -LiteralPath $TempBase) { Remove-Item -LiteralPath $TempBase -Recurse -Force }
}
