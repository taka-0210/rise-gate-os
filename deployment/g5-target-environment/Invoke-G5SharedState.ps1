[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [switch] $VerifyPersistenceOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$HelperGeneration = 'g5-shared-state-v1'
$ExpectedContractId = 'company-os.ir1.g5-shared-state.v1'
$ExpectedContractSha256 = 'cc11ad5a67e0f3f869da15091d91740f9e4d584d90995bd86d38ae22d7a282d1'
$ExpectedSourceInspectorSha256 = '72f7d8016d6d42dd25ceb44994b00a31e279bc7daad3e4fb5911b00f6539d683'
$ExpectedTargetManagerSha256 = '6b019523e95688ebc013f01e3bfc58d895e0e6afbe6af1e34e193da8fc3ddf7f'
$ExpectedProjectorSha256 = '5f438829ab57f3327797f9889cd6e9287219e644f2dd0dd5b5d99b12090f9509'
$ExpectedAllowlistSha256 = 'd408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec'
$ExpectedG5BReceiptSha256 = 'ec24b30e62c34564aaaaccfa928edbac98f95e7e4748594422b641f4bbbae7c3'
$ExpectedG5CReceiptSha256 = 'a1dd2871236868affe3b4a9d5454dc87eb614b15f1049e3a555c3700dec59faf'
$ExpectedG5CStateSha256 = 'e696b86dbb818c85f99d0115f547408cf193eb1fd67693807a7dd9f28d2ed99c'
$ExpectedSkeletonReceiptSha256 = '51fb756414218c7b4a742c03620d197783d3191cbb0ef5f803a74ac3e91c2057'
$ExpectedSkeletonStateSha256 = '54bb6edcc1e5d23fc2066f30b8cfa40d713da8168dbf10ec22e6c03349c9a0d3'
$ExpectedSourceG1EvidenceSha256 = '15e20d272f39d1bd69290af6b5c2f681b52bb42a09273b783ca77ad7eacf4340'
$ExpectedSourceHostEvidenceSha256 = '03f0a69dbeac35747082f06c34e2b492f03cd0bcc8ebd18dc251c81372d0a7ac'
$ExpectedTargetHostReceiptSha256 = '834b0309366619f960c12e34a27d41f3633e2a8cf0f09fdc29fa18b96d4526ba'
$ExpectedTargetKnownHostsSha256 = 'ba34cd1acb3c594d282a6c78a4352971f9f4831097c30f4423f6a326ceaa8983'

$SourceAlias = 'company-os-production'
$SourceHost = 'sv17033.xserver.jp'
$SourceUser = 'xs257823'
$SourcePort = '10022'
$SourceIdentityLeaf = 'codex-company-os-production'
$SourceIdentityFingerprint = 'SHA256:d/dPUPa6KjfXVOpG1Sqqp66GMFjYtxYUR1dqhaRBQBg'
$SourceHostKeyFingerprint = 'SHA256:lkUHlNS7K7nVe/slV97qC08nvzqNzsgUsVD9p2Q1KCs'
$SourceEnv = '/home/xs257823/rise-gate.com/rise-gate-os/.env'
$SourceStorageApp = '/home/xs257823/rise-gate.com/rise-gate-os/storage/app'

$TargetHost = 'sv17169.xserver.jp'
$TargetUser = 'xs377816'
$TargetPort = '10022'
$TargetIdentityLeaf = 'codex-company-os-target-production'
$TargetIdentityFingerprint = 'SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g'
$TargetHostKeyFingerprint = 'SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8'
$TargetStagingRoot = "/home/xs377816/company-os.jp/company-os-app/shared/.g5-shared-state-$Candidate"
$TargetStagingSourceEnv = "$TargetStagingRoot/source.env"
$TargetStagingProjectedEnv = "$TargetStagingRoot/.env.incoming"
$TargetStagingStorage = "$TargetStagingRoot/storage"
$TargetStagingStorageApp = "$TargetStagingStorage/app"
$Confirmation = 'IR1-G5-SHARED-STATE'
$Utf8NoBom = [Text.UTF8Encoding]::new($false)

$ConnectionAttempted = $false
$SourceConnectionAttempted = $false
$TargetConnectionAttempted = $false
$RemoteProcessCount = 0
$TargetPrepared = $false
$TargetFinalized = $false
$CleanupState = 'not_required'
$RollbackState = 'not_required'
$State = $null
$StatePath = $null
$StateWriteGeneration = 0
$FailureStage = 'LOCAL_PRECONDITIONS'
$LocalSubstage = 'LOCAL_PRECONDITIONS'
$TransientRoot = $null
$TransientConfigPath = $null
$TransientResidualCount = 0
$ProductionMutation = 'false'
$ssh = $null
$targetArgs = @()
$targetManager = $null

function Stop-G5Shared([string] $Code) {
    throw [InvalidOperationException]::new($Code)
}

function Get-FileSha256([string] $Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function Get-TextSha256([AllowEmptyString()][string] $Value) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($Utf8NoBom.GetBytes($Value)))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function Get-SafeErrorCode([Exception] $Exception) {
    if ($Exception.Message -match '^[A-Z][A-Z0-9_]{2,100}$') { return $Exception.Message }
    return 'UNEXPECTED_LOCAL_FAILURE'
}

function Read-LfText([string] $Path) {
    $text = [IO.File]::ReadAllText($Path)
    return $text.Replace("`r`n", "`n").Replace("`r", "`n")
}

function Assert-NativeArguments([string[]] $Arguments) {
    foreach ($argument in $Arguments) {
        if ([string]::IsNullOrWhiteSpace($argument) -or $argument -match '\s') {
            Stop-G5Shared 'NATIVE_ARGUMENT_REJECTED'
        }
    }
}

function Save-Json([string] $Path, [object] $Value) {
    $json = $Value | ConvertTo-Json -Depth 12
    $temporaryPath = $Path + '.tmp'
    $backupPath = $Path + '.previous'
    if ([IO.File]::Exists($temporaryPath) -or [IO.File]::Exists($backupPath)) {
        Stop-G5Shared 'STATE_PERSISTENCE_RESIDUAL_PRESENT'
    }
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, $Utf8NoBom)
    if ([IO.File]::Exists($Path)) {
        [IO.File]::Replace($temporaryPath, $Path, $backupPath)
        [IO.File]::Delete($backupPath)
    } else {
        [IO.File]::Move($temporaryPath, $Path)
    }
}

function Save-State {
    if ($null -ne $script:State -and -not [string]::IsNullOrWhiteSpace($script:StatePath)) {
        $script:StateWriteGeneration++
        $script:State.local_state_generation = $script:StateWriteGeneration
        Save-Json $script:StatePath $script:State
    }
}

function Invoke-StatePersistenceSelfTest {
    $savedState = $script:State
    $savedPath = $script:StatePath
    $savedGeneration = $script:StateWriteGeneration
    $fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ("company-os-g5-shared-state-$PID-" + [guid]::NewGuid().ToString('N'))
    $fixturePath = Join-Path $fixtureRoot 'execution-state.json'
    try {
        [IO.Directory]::CreateDirectory($fixtureRoot) | Out-Null
        $script:StateWriteGeneration = 0
        $script:StatePath = $fixturePath
        $script:State = [ordered]@{ status='ATTEMPT_STARTED'; local_state_generation=0; production_connection_attempted=$false }
        Save-State
        $hash1 = Get-FileSha256 $fixturePath
        $script:State.status = 'IN_PROGRESS'
        Save-State
        $hash2 = Get-FileSha256 $fixturePath
        $script:State.status = 'STOP'
        Save-State
        $roundTrip = Get-Content -Raw -LiteralPath $fixturePath | ConvertFrom-Json
        if ($hash1 -eq $hash2 -or $roundTrip.local_state_generation -ne 3 -or
            (Test-Path -LiteralPath ($fixturePath + '.tmp')) -or
            (Test-Path -LiteralPath ($fixturePath + '.previous'))) {
            Stop-G5Shared 'STATE_PERSISTENCE_SELF_TEST_FAILED'
        }
        return [pscustomobject]@{ generations=3; atomic_initial_move='PASS'; atomic_existing_replace='PASS'; residual=0 }
    } finally {
        $script:State = $savedState
        $script:StatePath = $savedPath
        $script:StateWriteGeneration = $savedGeneration
        if ([IO.Directory]::Exists($fixtureRoot)) { [IO.Directory]::Delete($fixtureRoot, $true) }
    }
}

function Invoke-Native(
    [string] $File,
    [string[]] $Arguments,
    [AllowNull()][string] $InputText,
    [ValidateSet('none','source','target','both')][string] $ConnectionScope
) {
    Assert-NativeArguments $Arguments
    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $File
    $startInfo.Arguments = $Arguments -join ' '
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    try {
        if (-not $process.Start()) { Stop-G5Shared 'NATIVE_PROCESS_START_FAILED' }
        if ($ConnectionScope -ne 'none') {
            $script:ConnectionAttempted = $true
            $script:RemoteProcessCount++
            if ($ConnectionScope -in @('source','both')) { $script:SourceConnectionAttempted = $true }
            if ($ConnectionScope -in @('target','both')) { $script:TargetConnectionAttempted = $true }
            if ($null -ne $script:State) {
                $script:State.production_connection_attempted = $true
                $script:State.source_connection_attempted = $script:SourceConnectionAttempted
                $script:State.target_connection_attempted = $script:TargetConnectionAttempted
                $script:State.remote_process_count = $script:RemoteProcessCount
                $script:State.local_processing_substage = $script:LocalSubstage
                Save-State
            }
        }
        if ($null -ne $InputText) {
            $bytes = $Utf8NoBom.GetBytes($InputText)
            $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
            $process.StandardInput.BaseStream.Flush()
        }
        $process.StandardInput.Close()
        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode=$process.ExitCode; Stdout=$stdout; Stderr=$stderr }
    } finally {
        $process.Dispose()
    }
}

function Parse-SafeOutput([string] $Value, [string] $ExpectedHeader) {
    if ($Utf8NoBom.GetByteCount($Value) -gt 32768 -or
        $Value -match '/home/[A-Za-z0-9._/-]+' -or
        $Value -match '(?i)(APP_KEY|DB_PASSWORD|OPENAI_API_KEY|SECRET_ACCESS_KEY|PRIVATE KEY|BEARER|CREDENTIAL)') {
        Stop-G5Shared 'UNSAFE_REMOTE_OUTPUT'
    }
    $map = [ordered]@{}
    foreach ($line in ($Value -split '\r?\n' | Where-Object { $_ -ne '' })) {
        if ($line -notmatch '^([A-Za-z0-9_]+)=([A-Za-z0-9_.:-]+)$') {
            Stop-G5Shared 'REMOTE_OUTPUT_CONTRACT_INVALID'
        }
        if ($map.Contains($Matches[1])) { Stop-G5Shared 'REMOTE_OUTPUT_DUPLICATE_KEY' }
        $map[$Matches[1]] = $Matches[2]
    }
    if (-not $map.Contains($ExpectedHeader)) { Stop-G5Shared 'REMOTE_STATUS_MISSING' }
    return $map
}

function Assert-RemotePass([object] $Result, [string] $Header) {
    $parsed = Parse-SafeOutput $Result.Stdout $Header
    if ($Result.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($Result.Stderr) -or $parsed[$Header] -ne 'PASS') {
        Stop-G5Shared 'REMOTE_STEP_FAILED'
    }
    if (-not $parsed.Contains('secret_output') -or $parsed.secret_output -ne 'false') {
        Stop-G5Shared 'REMOTE_SECRET_OUTPUT_CONTRACT_MISMATCH'
    }
    return $parsed
}

function Get-SshArguments([string] $RemoteHost, [string] $RemoteUser, [string] $RemotePort, [string] $IdentityPath, [string] $KnownHostsPath) {
    return @(
        '-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ConnectTimeout=10','-o','ClearAllForwardings=yes',
        '-o','LogLevel=ERROR','-o','HostKeyAlgorithms=ssh-ed25519',
        '-o',"UserKnownHostsFile=$KnownHostsPath",'-p',$RemotePort,'-l',$RemoteUser,'-i',$IdentityPath,'-T',$RemoteHost
    )
}

function Assert-KeyFingerprint([string] $Keygen, [string] $Path, [string] $Fingerprint) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Stop-G5Shared 'SSH_IDENTITY_MISSING' }
    $result = Invoke-Native $Keygen @('-lf',$Path) $null 'none'
    if ($result.ExitCode -ne 0 -or $result.Stdout -notmatch [regex]::Escape($Fingerprint)) {
        Stop-G5Shared 'SSH_IDENTITY_FINGERPRINT_MISMATCH'
    }
}

function Assert-HostKey([string] $Keygen, [string] $KnownHostsPath, [string] $RemoteHost, [string] $RemotePort, [string] $Fingerprint) {
    if (-not (Test-Path -LiteralPath $KnownHostsPath -PathType Leaf)) { Stop-G5Shared 'KNOWN_HOSTS_MISSING' }
    $lookup = "[$RemoteHost]:$RemotePort"
    $found = Invoke-Native $Keygen @('-F',$lookup,'-f',$KnownHostsPath) $null 'none'
    if ($found.ExitCode -ne 0) { Stop-G5Shared 'HOST_KEY_NOT_REGISTERED' }
    $lines = (($found.Stdout -split '\r?\n' | Where-Object { $_ -ne '' -and $_ -notmatch '^#' }) -join [Environment]::NewLine)
    $fingerprintResult = Invoke-Native $Keygen @('-lf','-') $lines 'none'
    if ($fingerprintResult.ExitCode -ne 0 -or $fingerprintResult.Stdout -notmatch [regex]::Escape($Fingerprint)) {
        Stop-G5Shared 'HOST_KEY_FINGERPRINT_MISMATCH'
    }
}

function Assert-FileBinding([string] $Path, [string] $Hash, [string] $Code) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf) -or (Get-FileSha256 $Path) -ne $Hash) {
        Stop-G5Shared $Code
    }
}

try {
    if ($VerifyOnly -and $VerifyPersistenceOnly) { Stop-G5Shared 'VERIFY_MODE_CONFLICT' }
    $Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $ContractPath = Join-Path $PSScriptRoot 'shared-state-contract.json'
    $SourceInspectorPath = Join-Path $PSScriptRoot 'inspect-shared-source.sh'
    $TargetManagerPath = Join-Path $PSScriptRoot 'manage-shared-target.sh'
    $ProjectorPath = Join-Path $PSScriptRoot 'shared-state-projector.php'
    $AllowlistPath = Join-Path $PSScriptRoot 'shared-state-env-allowlist.txt'
    $G5BReceiptPath = Join-Path $Root "storage\app\release-audit\production-g5b-cross-server-reconciliation-$Candidate\g5b-reconciled-disposition.json"
    $G5CRoot = Join-Path $Root "storage\app\release-audit\production-g5c-new-target-rehearsal-corrective-1-$Candidate"
    $G5CReceiptPath = Join-Path $G5CRoot 'capability-rehearsal.json'
    $G5CStatePath = Join-Path $G5CRoot 'execution-state.json'
    $SkeletonRoot = Join-Path $Root "storage\app\release-audit\production-g5-target-skeleton-build-$Candidate"
    $SkeletonReceiptPath = Join-Path $SkeletonRoot 'target-skeleton-build.json'
    $SkeletonStatePath = Join-Path $SkeletonRoot 'execution-state.json'
    $SourceG1Path = Join-Path $Root "storage\app\release-audit\production-g1-gap-closure-$Candidate\g1-evidence.json"
    $SourceHostEvidencePath = Join-Path $Root "storage\app\release-audit\production-r0-human-$Candidate-corrective-2\host.stdout.json"
    $TargetBindingRoot = Join-Path $Root "storage\app\release-audit\production-g5b-new-target-binding-$Candidate"
    $TargetHostReceiptPath = Join-Path $TargetBindingRoot 'host-key-verification.json'
    $TargetKnownHostsPath = Join-Path $TargetBindingRoot 'known_hosts'
    $EvidenceRoot = Join-Path $Root "storage\app\release-audit\production-g5-shared-state-$Candidate"
    $ReceiptPath = Join-Path $EvidenceRoot 'shared-state.json'
    $StatePath = Join-Path $EvidenceRoot 'execution-state.json'
    $UserSshRoot = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh'
    $SourceIdentityPath = Join-Path $UserSshRoot $SourceIdentityLeaf
    $TargetIdentityPath = Join-Path $UserSshRoot $TargetIdentityLeaf
    $SourceKnownHostsPath = Join-Path $UserSshRoot 'known_hosts'

    Assert-FileBinding $ContractPath $ExpectedContractSha256 'SHARED_STATE_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $SourceInspectorPath $ExpectedSourceInspectorSha256 'SOURCE_INSPECTOR_BINDING_MISMATCH'
    Assert-FileBinding $TargetManagerPath $ExpectedTargetManagerSha256 'TARGET_MANAGER_BINDING_MISMATCH'
    Assert-FileBinding $ProjectorPath $ExpectedProjectorSha256 'PROJECTOR_BINDING_MISMATCH'
    Assert-FileBinding $AllowlistPath $ExpectedAllowlistSha256 'ALLOWLIST_BINDING_MISMATCH'
    Assert-FileBinding $G5BReceiptPath $ExpectedG5BReceiptSha256 'G5B_EVIDENCE_BINDING_MISMATCH'
    Assert-FileBinding $G5CReceiptPath $ExpectedG5CReceiptSha256 'G5C_RECEIPT_BINDING_MISMATCH'
    Assert-FileBinding $G5CStatePath $ExpectedG5CStateSha256 'G5C_STATE_BINDING_MISMATCH'
    Assert-FileBinding $SkeletonReceiptPath $ExpectedSkeletonReceiptSha256 'SKELETON_RECEIPT_BINDING_MISMATCH'
    Assert-FileBinding $SkeletonStatePath $ExpectedSkeletonStateSha256 'SKELETON_STATE_BINDING_MISMATCH'
    Assert-FileBinding $SourceG1Path $ExpectedSourceG1EvidenceSha256 'SOURCE_G1_EVIDENCE_BINDING_MISMATCH'
    Assert-FileBinding $SourceHostEvidencePath $ExpectedSourceHostEvidenceSha256 'SOURCE_HOST_EVIDENCE_BINDING_MISMATCH'
    Assert-FileBinding $TargetHostReceiptPath $ExpectedTargetHostReceiptSha256 'TARGET_HOST_RECEIPT_BINDING_MISMATCH'
    Assert-FileBinding $TargetKnownHostsPath $ExpectedTargetKnownHostsSha256 'TARGET_KNOWN_HOSTS_BINDING_MISMATCH'

    $contract = Get-Content -Raw -LiteralPath $ContractPath | ConvertFrom-Json
    if ($contract.contract_id -ne $ExpectedContractId -or $contract.candidate -ne $Candidate -or
        $contract.candidate_environment_contract.allowlist_count -ne 172 -or
        $contract.candidate_environment_contract.allowlist_sha256 -ne $ExpectedAllowlistSha256 -or
        $contract.implementation_binding.source_inspector_sha256 -ne $ExpectedSourceInspectorSha256 -or
        $contract.implementation_binding.target_manager_sha256 -ne $ExpectedTargetManagerSha256 -or
        $contract.implementation_binding.projector_sha256 -ne $ExpectedProjectorSha256 -or
        $contract.source.host -ne $SourceHost -or $contract.source.user -ne $SourceUser -or
        $contract.target.host -ne $TargetHost -or $contract.target.user -ne $TargetUser -or
        [bool] $contract.candidate_environment_contract.secret_values_output -or
        [bool] $contract.production_mutation_authorized -or
        $contract.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION -ne 'PENDING_G5_PUBLIC_ENTRY_GATE') {
        Stop-G5Shared 'SHARED_STATE_CONTRACT_MISMATCH'
    }
    $skeletonReceipt = Get-Content -Raw -LiteralPath $SkeletonReceiptPath | ConvertFrom-Json
    $skeletonState = Get-Content -Raw -LiteralPath $SkeletonStatePath | ConvertFrom-Json
    if ($skeletonReceipt.status -ne 'PASS' -or
        $skeletonReceipt.operation.production_mutation_scope -ne 'exact_empty_target_skeleton_created' -or
        $skeletonState.target_topology_state -ne 'exact_empty_skeleton' -or
        $skeletonReceipt.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION -ne 'PENDING_G5_PUBLIC_ENTRY_GATE' -or
        [bool] $skeletonReceipt.deploy_authorized -or $skeletonState.status -ne 'PASS') {
        Stop-G5Shared 'SKELETON_FORMAL_PASS_MISMATCH'
    }

    $persistence = Invoke-StatePersistenceSelfTest
    if ($VerifyPersistenceOnly) {
        Write-Output 'G5_SHARED_STATE_PERSISTENCE_VERIFY_ONLY=PASS'
        Write-Output "helper_generation=$HelperGeneration"
        Write-Output "generation_count=$($persistence.generations)"
        Write-Output "atomic_initial_move=$($persistence.atomic_initial_move)"
        Write-Output "atomic_existing_replace=$($persistence.atomic_existing_replace)"
        Write-Output "residual_entry_count=$($persistence.residual)"
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_mutation=false'
        Write-Output 'shared_state_operation=not_executed'
        exit 0
    }

    $ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue
    $scp = Get-Command scp.exe -ErrorAction SilentlyContinue
    $keygen = Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $scp -or $null -eq $keygen) { Stop-G5Shared 'OPENSSH_CLIENT_UNAVAILABLE' }
    Assert-KeyFingerprint $keygen.Source $SourceIdentityPath $SourceIdentityFingerprint
    Assert-KeyFingerprint $keygen.Source $TargetIdentityPath $TargetIdentityFingerprint
    Assert-HostKey $keygen.Source $SourceKnownHostsPath $SourceHost $SourcePort $SourceHostKeyFingerprint
    Assert-HostKey $keygen.Source $TargetKnownHostsPath $TargetHost $TargetPort $TargetHostKeyFingerprint

    $sourceExpanded = Invoke-Native $ssh.Source @('-G',$SourceAlias) $null 'none'
    $sourceConfig = @{}
    foreach ($line in ($sourceExpanded.Stdout -split '\r?\n')) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) { $sourceConfig[$parts[0].ToLowerInvariant()] = $parts[1] }
    }
    if ($sourceExpanded.ExitCode -ne 0 -or $sourceConfig.hostname -ne $SourceHost -or
        $sourceConfig.user -ne $SourceUser -or $sourceConfig.port -ne $SourcePort -or
        $sourceConfig.identitiesonly -ne 'yes' -or
        (Split-Path -Leaf $sourceConfig.identityfile.Trim('"')) -ne $SourceIdentityLeaf) {
        Stop-G5Shared 'SOURCE_SSH_BINDING_MISMATCH'
    }

    if (Test-Path -LiteralPath $EvidenceRoot) { Stop-G5Shared 'SHARED_STATE_ATTEMPT_ALREADY_RECORDED' }
    $allowlistRaw = Read-LfText $AllowlistPath
    if (-not $allowlistRaw.EndsWith("`n")) { Stop-G5Shared 'ALLOWLIST_FINAL_NEWLINE_MISSING' }
    $allowlistB64 = [Convert]::ToBase64String($Utf8NoBom.GetBytes($allowlistRaw))
    $sourceInspector = Read-LfText $SourceInspectorPath
    $targetManager = Read-LfText $TargetManagerPath
    $projector = Read-LfText $ProjectorPath

    $TransientRoot = Join-Path ([IO.Path]::GetTempPath()) ("company-os-g5-shared-$PID-" + [guid]::NewGuid().ToString('N'))
    $TransientConfigPath = Join-Path $TransientRoot 'ssh-config'
    [IO.Directory]::CreateDirectory($TransientRoot) | Out-Null
    foreach ($path in @($TransientConfigPath,$SourceIdentityPath,$TargetIdentityPath,$SourceKnownHostsPath,$TargetKnownHostsPath)) {
        if ($path -match '\s') { Stop-G5Shared 'TRANSIENT_SSH_PATH_CONTAINS_WHITESPACE' }
    }
    $slash = { param([string] $p) $p.Replace('\','/') }
    $configText = @"
Host g5-legacy-source
    HostName $SourceHost
    User $SourceUser
    Port $SourcePort
    IdentityFile $(& $slash $SourceIdentityPath)
    IdentitiesOnly yes
    UserKnownHostsFile $(& $slash $SourceKnownHostsPath)
    StrictHostKeyChecking yes
    HostKeyAlgorithms ssh-ed25519
    BatchMode yes
    ClearAllForwardings yes
    LogLevel ERROR
Host g5-new-target
    HostName $TargetHost
    User $TargetUser
    Port $TargetPort
    IdentityFile $(& $slash $TargetIdentityPath)
    IdentitiesOnly yes
    UserKnownHostsFile $(& $slash $TargetKnownHostsPath)
    StrictHostKeyChecking yes
    HostKeyAlgorithms ssh-ed25519
    BatchMode yes
    ClearAllForwardings yes
    LogLevel ERROR
"@
    [IO.File]::WriteAllText($TransientConfigPath, $configText.Replace("`r`n","`n"), $Utf8NoBom)
    foreach ($binding in @(
        [pscustomobject]@{ Alias='g5-legacy-source'; HostName=$SourceHost; User=$SourceUser; Port=$SourcePort; Identity=$SourceIdentityLeaf },
        [pscustomobject]@{ Alias='g5-new-target'; HostName=$TargetHost; User=$TargetUser; Port=$TargetPort; Identity=$TargetIdentityLeaf }
    )) {
        $expanded = Invoke-Native $ssh.Source @('-G','-F',$TransientConfigPath,$binding.Alias) $null 'none'
        $values = @{}
        foreach ($line in ($expanded.Stdout -split '\r?\n')) {
            $parts = $line -split '\s+', 2
            if ($parts.Count -eq 2) { $values[$parts[0].ToLowerInvariant()] = $parts[1] }
        }
        if ($expanded.ExitCode -ne 0 -or $values.hostname -ne $binding.HostName -or
            $values.user -ne $binding.User -or $values.port -ne $binding.Port -or
            $values.identitiesonly -ne 'yes' -or
            (Split-Path -Leaf $values.identityfile.Trim('"')) -ne $binding.Identity) {
            Stop-G5Shared 'TRANSIENT_SSH_CONFIG_BINDING_MISMATCH'
        }
    }
    $sourceArgs = Get-SshArguments $SourceHost $SourceUser $SourcePort $SourceIdentityPath $SourceKnownHostsPath
    $targetArgs = Get-SshArguments $TargetHost $TargetUser $TargetPort $TargetIdentityPath $TargetKnownHostsPath

    if ($VerifyOnly) {
        $fixture = Parse-SafeOutput "G5_SHARED_SOURCE_PREFLIGHT=STOP`nsafe_error_code=VERIFY_ONLY_FIXTURE`nsecret_output=false`n" 'G5_SHARED_SOURCE_PREFLIGHT'
        if ($fixture.G5_SHARED_SOURCE_PREFLIGHT -ne 'STOP') { Stop-G5Shared 'SAFE_OUTPUT_PARSER_SELF_TEST_FAILED' }
        Write-Output 'G5_SHARED_STATE_VERIFY_ONLY=PASS'
        Write-Output "candidate=$Candidate"
        Write-Output "helper_generation=$HelperGeneration"
        Write-Output "contract_sha256=$ExpectedContractSha256"
        Write-Output "allowlist_sha256=$ExpectedAllowlistSha256"
        Write-Output 'allowlist_key_count=172'
        Write-Output 'secret_source=legacy_allowlisted_projection'
        Write-Output 'secret_values_output=false'
        Write-Output 'target_env_mode=0600'
        Write-Output 'storage_seed_scope=storage_app_only'
        Write-Output 'storage_final_delta_required=true'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_mutation=false'
        Write-Output 'shared_state_operation=not_executed'
        [IO.Directory]::Delete($TransientRoot, $true)
        if ([IO.Directory]::Exists($TransientRoot)) { Stop-G5Shared 'VERIFY_ONLY_TRANSIENT_CONFIG_RESIDUAL' }
        Write-Output 'transient_config_residual_count=0'
        Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
        exit 0
    }

    [IO.Directory]::CreateDirectory($EvidenceRoot) | Out-Null
    $State = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='ATTEMPT_STARTED'
        contract_sha256=$ExpectedContractSha256; source_inspector_sha256=$ExpectedSourceInspectorSha256
        target_manager_sha256=$ExpectedTargetManagerSha256; projector_sha256=$ExpectedProjectorSha256
        allowlist_sha256=$ExpectedAllowlistSha256; production_connection_attempted=$false
        source_connection_attempted=$false; target_connection_attempted=$false; remote_process_count=0
        production_mutation_scope='none'; target_prepared=$false; target_finalized=$false
        cleanup_state='not_required'; rollback_state='not_required'; transient_config_residual_count='unknown'
        raw_output_stored=$false; secret_values_output=$false; retry_performed=$false; retry_available=$false
        local_processing_substage='ATTEMPT_INITIALIZED'; safe_error_code=$null; local_state_generation=0
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
    }
    Save-State

    $FailureStage = 'SOURCE_READ_ONLY_PREFLIGHT'
    $LocalSubstage = 'SOURCE_PREFLIGHT_PROCESS_PENDING'
    $sourcePreflightResult = Invoke-Native $ssh.Source ($sourceArgs + @('sh','-s','--',$Confirmation)) $sourceInspector 'source'
    $sourcePreflight = Assert-RemotePass $sourcePreflightResult 'G5_SHARED_SOURCE_PREFLIGHT'
    if ($sourcePreflight.production_change_scope -ne 'none_read_only_source' -or
        $sourcePreflight.source_binding -ne 'exact_legacy_application') {
        Stop-G5Shared 'SOURCE_PREFLIGHT_DISPOSITION_MISMATCH'
    }

    $FailureStage = 'SOURCE_READ_ONLY_INVENTORY'
    $LocalSubstage = 'SOURCE_INVENTORY_PROCESS_PENDING'
    $sourceInventoryResult = Invoke-Native $ssh.Source ($sourceArgs + @(
        'php','--','inspect-source',$SourceEnv,$SourceStorageApp,$allowlistB64
    )) $projector 'source'
    $sourceInventory = Assert-RemotePass $sourceInventoryResult 'G5_SHARED_SOURCE_INVENTORY'
    foreach ($key in @('source_env_sha256','env_payload_sha256','env_payload_bytes','storage_app_manifest_sha256',
        'storage_app_file_count','storage_app_directory_count','storage_app_total_bytes')) {
        if (-not $sourceInventory.Contains($key)) { Stop-G5Shared 'SOURCE_INVENTORY_INCOMPLETE' }
    }
    if ($sourceInventory.candidate_allowlist_sha256 -ne $ExpectedAllowlistSha256 -or
        $sourceInventory.candidate_allowlist_count -ne '172' -or
        $sourceInventory.production_change_scope -ne 'none_read_only_source') {
        Stop-G5Shared 'SOURCE_INVENTORY_DISPOSITION_MISMATCH'
    }

    $FailureStage = 'TARGET_CANDIDATE_BOUND_PREPARE'
    $LocalSubstage = 'TARGET_PREPARE_PROCESS_PENDING'
    $prepareResult = Invoke-Native $ssh.Source ($targetArgs + @(
        'bash','-s','--',$Confirmation,'prepare',$sourceInventory.storage_app_total_bytes
    )) $targetManager 'target'
    $prepare = Assert-RemotePass $prepareResult 'G5_SHARED_TARGET_PREPARE'
    if ($prepare.production_change_scope -ne 'candidate_bound_shared_staging_created' -or
        $prepare.staging_source_env_mode -ne '0600' -or $prepare.target_public_entry_changed -ne 'false') {
        Stop-G5Shared 'TARGET_PREPARE_DISPOSITION_MISMATCH'
    }
    $TargetPrepared = $true
    $ProductionMutation = 'candidate_bound_shared_staging_created'
    $State.target_prepared = $true
    $State.production_mutation_scope = 'candidate_bound_shared_staging_created'
    Save-State

    $FailureStage = 'ENCRYPTED_ENV_TRANSFER'
    $LocalSubstage = 'ENV_SCP_PROCESS_PENDING'
    $envScp = Invoke-Native $scp.Source @(
        '-3','-q','-F',$TransientConfigPath,
        "g5-legacy-source:$SourceEnv","g5-new-target:$TargetStagingSourceEnv"
    ) $null 'both'
    if ($envScp.ExitCode -ne 0) { Stop-G5Shared 'ENV_TRANSFER_FAILED' }

    $FailureStage = 'ENCRYPTED_STORAGE_SEED_TRANSFER'
    $LocalSubstage = 'STORAGE_SCP_PROCESS_PENDING'
    $storageScp = Invoke-Native $scp.Source @(
        '-3','-q','-r','-F',$TransientConfigPath,
        "g5-legacy-source:$SourceStorageApp","g5-new-target:$TargetStagingStorage/"
    ) $null 'both'
    if ($storageScp.ExitCode -ne 0) { Stop-G5Shared 'STORAGE_TRANSFER_FAILED' }

    $FailureStage = 'TARGET_ENV_ALLOWLIST_PROJECTION'
    $LocalSubstage = 'TARGET_ENV_PROJECTION_PROCESS_PENDING'
    $projectionResult = Invoke-Native $ssh.Source ($targetArgs + @(
        'php','--','project-target',$TargetStagingSourceEnv,$TargetStagingProjectedEnv,$allowlistB64,
        $sourceInventory.env_payload_sha256,$sourceInventory.env_payload_bytes,$sourceInventory.source_env_sha256
    )) $projector 'target'
    $projection = Assert-RemotePass $projectionResult 'G5_SHARED_ENV_PROJECTION'
    if ($projection.env_payload_sha256 -ne $sourceInventory.env_payload_sha256 -or
        $projection.source_env_sha256 -ne $sourceInventory.source_env_sha256 -or
        $projection.target_env_mode -ne '0600' -or $projection.target_env_values_output -ne 'false') {
        Stop-G5Shared 'TARGET_ENV_PROJECTION_MISMATCH'
    }

    $FailureStage = 'TARGET_STORAGE_MANIFEST_VERIFY'
    $LocalSubstage = 'TARGET_STORAGE_VERIFY_PROCESS_PENDING'
    $storageResult = Invoke-Native $ssh.Source ($targetArgs + @(
        'php','--','inspect-target-storage',$TargetStagingStorageApp,$sourceInventory.storage_app_manifest_sha256,
        $sourceInventory.storage_app_file_count,$sourceInventory.storage_app_directory_count,$sourceInventory.storage_app_total_bytes
    )) $projector 'target'
    $storage = Assert-RemotePass $storageResult 'G5_SHARED_TARGET_STORAGE'

    $FailureStage = 'TARGET_SHARED_STATE_FINALIZE'
    $LocalSubstage = 'TARGET_FINALIZE_PROCESS_PENDING'
    $finalizeResult = Invoke-Native $ssh.Source ($targetArgs + @(
        'bash','-s','--',$Confirmation,'finalize',$sourceInventory.env_payload_sha256,$sourceInventory.env_payload_bytes,
        $sourceInventory.storage_app_manifest_sha256,$sourceInventory.storage_app_file_count,
        $sourceInventory.storage_app_directory_count,$sourceInventory.storage_app_total_bytes
    )) $targetManager 'target'
    $finalize = Assert-RemotePass $finalizeResult 'G5_SHARED_TARGET_FINALIZE'
    foreach ($pair in (@{
        target_shared_env_created='true'; target_shared_env_mode='0600'; target_shared_storage_created='true'
        target_storage_directory_mode='0750'; target_storage_file_mode='0640'; php_cli_env_readability='true'
        php_cli_storage_writeability='true'; raw_source_env_removed='true'; storage_final_delta_required='true'
        cleanup_state='complete'; staging_residual_entry_count='0'; target_public_entry_changed='false'
        legacy_production_changed='false'; database_connection='not_attempted'; migration='not_attempted'
        application_release_created='false'; release_marker_binding='not_attempted'; dns_ssl_change='not_attempted'
        deploy='not_attempted'; usable_backup='unknown'; db_restore_readiness='blocker'
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
    }).GetEnumerator()) {
        if (-not $finalize.Contains($pair.Key) -or $finalize[$pair.Key] -ne $pair.Value) {
            Stop-G5Shared 'TARGET_FINALIZE_DISPOSITION_MISMATCH'
        }
    }
    $TargetFinalized = $true
    $TargetPrepared = $false
    $ProductionMutation = 'exact_new_target_shared_env_and_storage_seed'
    $CleanupState = 'complete'
    $RollbackState = 'not_required'

    if ([IO.Directory]::Exists($TransientRoot)) { [IO.Directory]::Delete($TransientRoot, $true) }
    $TransientResidualCount = if ([IO.Directory]::Exists($TransientRoot)) { 1 } else { 0 }
    if ($TransientResidualCount -ne 0) { Stop-G5Shared 'TRANSIENT_SSH_CONFIG_RESIDUAL' }

    $receipt = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='PASS'
        contract_sha256=$ExpectedContractSha256; allowlist_sha256=$ExpectedAllowlistSha256
        prerequisite_evidence=[ordered]@{
            g5b_receipt_sha256=$ExpectedG5BReceiptSha256; g5c_receipt_sha256=$ExpectedG5CReceiptSha256
            g5c_state_sha256=$ExpectedG5CStateSha256; skeleton_receipt_sha256=$ExpectedSkeletonReceiptSha256
            skeleton_state_sha256=$ExpectedSkeletonStateSha256; source_g1_evidence_sha256=$ExpectedSourceG1EvidenceSha256
            source_host_evidence_sha256=$ExpectedSourceHostEvidenceSha256
        }
        source=[ordered]@{
            binding='exact_legacy_authoritative_shared_state'; host=$SourceHost; user=$SourceUser; port=[int]$SourcePort
            read_only=$true; preflight=$sourcePreflight; inventory=$sourceInventory
        }
        target=[ordered]@{
            binding='exact_new_target_shared_root'; host=$TargetHost; user=$TargetUser; port=[int]$TargetPort
            prepare=$prepare; projection=$projection; storage_verification=$storage; finalize=$finalize
        }
        operation=[ordered]@{
            production_connection_attempted=$true; source_connection_attempted=$true; target_connection_attempted=$true
            remote_process_count=$RemoteProcessCount; production_mutation_scope='exact_new_target_shared_env_and_storage_seed'
            secret_source='legacy_allowlisted_projection'; secret_values_output=$false; raw_source_env_stored_locally=$false
            cleanup_state='complete'; rollback_state='not_required'; transient_config_residual_count=0
            retry_performed=$false; retry_available=$false; deploy_authorized=$false
        }
        blockers=[ordered]@{
            usable_backup='unknown'; db_restore_readiness='blocker'; storage_final_delta_required=$true
            application_release_binding='not_attempted'; public_entry_disposition='PENDING_G5_PUBLIC_ENTRY_GATE'
        }
        raw_remote_output_stored=$false
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }
    Save-Json $ReceiptPath $receipt
    $State.status = 'PASS'
    $State.production_mutation_scope = 'exact_new_target_shared_env_and_storage_seed'
    $State.target_prepared = $false
    $State.target_finalized = $true
    $State.cleanup_state = 'complete'
    $State.rollback_state = 'not_required'
    $State.transient_config_residual_count = 0
    $State.local_processing_substage = 'COMPLETE'
    Save-State

    Write-Output 'G5_SHARED_STATE=PASS'
    Write-Output 'production_connection_attempted=true'
    Write-Output 'production_mutation=exact_new_target_shared_env_and_storage_seed'
    Write-Output 'secret_source=legacy_allowlisted_projection'
    Write-Output 'secret_values_output=false'
    Write-Output 'target_env_mode=0600'
    Write-Output 'target_storage_directory_mode=0750'
    Write-Output 'target_storage_file_mode=0640'
    Write-Output 'runtime_owner_uid=20046'
    Write-Output 'runtime_owner_gid=1000'
    Write-Output 'storage_final_delta_required=true'
    Write-Output 'cleanup_state=complete'
    Write-Output 'rollback_state=not_required'
    Write-Output 'staging_residual_entry_count=0'
    Write-Output 'transient_config_residual_count=0'
    Write-Output 'usable_backup=unknown'
    Write-Output 'db_restore_readiness=blocker'
    Write-Output 'application_release_binding=not_attempted'
    Write-Output 'target_public_entry_changed=false'
    Write-Output 'migration=not_attempted'
    Write-Output 'deploy_authorized=false'
    Write-Output 'retry_available=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
} catch {
    $safeCode = Get-SafeErrorCode $_.Exception
    if ($TargetPrepared -and -not $TargetFinalized -and $null -ne $ssh) {
        try {
            $FailureStage = 'TARGET_PREPUBLISH_CLEANUP'
            $LocalSubstage = 'TARGET_CLEANUP_PROCESS_PENDING'
            $cleanupResult = Invoke-Native $ssh.Source ($targetArgs + @('bash','-s','--',$Confirmation,'cleanup')) $targetManager 'target'
            $cleanup = Assert-RemotePass $cleanupResult 'G5_SHARED_TARGET_CLEANUP'
            if ($cleanup.cleanup_state -eq 'complete' -and $cleanup.staging_residual_entry_count -eq '0') {
                $CleanupState = 'complete'
                $RollbackState = $cleanup.rollback_state
                $ProductionMutation = 'candidate_bound_staging_created_and_cleaned'
                $TargetPrepared = $false
            } else {
                $CleanupState = 'review_required'
                $RollbackState = 'separate_human_gate_required'
            }
        } catch {
            $CleanupState = 'review_required'
            $RollbackState = 'separate_human_gate_required'
        }
    } elseif ($TargetFinalized) {
        $CleanupState = 'complete'
        $RollbackState = 'separate_human_gate_required_for_published_state'
    }
    if ($null -ne $TransientRoot -and [IO.Directory]::Exists($TransientRoot)) {
        try { [IO.Directory]::Delete($TransientRoot, $true) } catch { }
    }
    $TransientResidualCount = if ($null -ne $TransientRoot -and [IO.Directory]::Exists($TransientRoot)) { 1 } else { 0 }
    $statePersistence = 'not_applicable'
    if ($null -ne $State) {
        $State.status = 'STOP'
        $State.safe_error_code = $safeCode
        $State.production_connection_attempted = $ConnectionAttempted
        $State.source_connection_attempted = $SourceConnectionAttempted
        $State.target_connection_attempted = $TargetConnectionAttempted
        $State.remote_process_count = $RemoteProcessCount
        $State.target_prepared = $TargetPrepared
        $State.target_finalized = $TargetFinalized
        $State.production_mutation_scope = $ProductionMutation
        $State.cleanup_state = $CleanupState
        $State.rollback_state = $RollbackState
        $State.transient_config_residual_count = $TransientResidualCount
        $State.local_processing_substage = $LocalSubstage
        try { Save-State; $statePersistence = 'saved' } catch { $statePersistence = 'failed' }
    }
    Write-Output 'G5_SHARED_STATE=STOP'
    Write-Output "safe_error_code=$safeCode"
    Write-Output "failure_stage=$FailureStage"
    Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "source_connection_attempted=$($SourceConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "target_connection_attempted=$($TargetConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "production_mutation=$ProductionMutation"
    Write-Output 'secret_values_output=false'
    Write-Output "cleanup_state=$CleanupState"
    Write-Output "rollback_state=$RollbackState"
    Write-Output "transient_config_residual_count=$TransientResidualCount"
    Write-Output "local_state_persistence=$statePersistence"
    Write-Output 'retry_performed=false'
    Write-Output 'retry_available=false'
    Write-Output 'deploy_authorized=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}
