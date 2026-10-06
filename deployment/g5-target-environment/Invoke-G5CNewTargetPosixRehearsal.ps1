[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [switch] $VerifyPersistenceOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$HelperGeneration = 'g5c-new-target-posix-rehearsal-corrective-1'
$ExpectedScriptSha256 = '8d8ea110d0d7069af519f8c9cab8240891a050e7f366a620f5efc9fe86d852f3'
$ExpectedG5BReceiptSha256 = 'ec24b30e62c34564aaaaccfa928edbac98f95e7e4748594422b641f4bbbae7c3'
$ExpectedHostReceiptSha256 = '834b0309366619f960c12e34a27d41f3633e2a8cf0f09fdc29fa18b96d4526ba'
$ExpectedKnownHostsSha256 = 'ba34cd1acb3c594d282a6c78a4352971f9f4831097c30f4423f6a326ceaa8983'
$ExpectedHost = 'sv17169.xserver.jp'
$ExpectedPort = '10022'
$ExpectedUser = 'xs377816'
$ExpectedIdentityLeaf = 'codex-company-os-target-production'
$ExpectedIdentityFingerprint = 'SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g'
$ExpectedHostKeyFingerprint = 'SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8'
$ExpectedRemoteHeader = 'G5C_POSIX_CAPABILITY_REHEARSAL'
$ExpectedConfirmation = 'IR1-G5C-POSIX-CAPABILITY-REHEARSAL'
$Utf8NoBom = [Text.UTF8Encoding]::new($false)

$ConnectionAttempted = $false
$NativeProcessStarted = $false
$RemoteExitCode = $null
$State = $null
$StatePath = $null
$FailureStage = 'LOCAL_PRECONDITIONS'
$LocalProcessingSubstage = 'LOCAL_PRECONDITIONS'
$ParsedRemoteEvidence = $null
$StateWriteGeneration = 0

function Stop-G5C([string] $Code) {
    throw [InvalidOperationException]::new($Code)
}

function Get-FileSha256([string] $Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function Get-TextSha256([AllowEmptyString()][string] $Value) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value)))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function Get-SafeErrorCode([Exception] $Exception) {
    if ($Exception.Message -match '^[A-Z][A-Z0-9_]{2,80}$') { return $Exception.Message }
    return 'UNEXPECTED_LOCAL_FAILURE'
}

function Read-LfScript([string] $Path) {
    $text = [IO.File]::ReadAllText($Path)
    $crlf = [string]::Concat([char] 13, [char] 10)
    $cr = [string] [char] 13
    $lf = [string] [char] 10
    $normalized = $text.Replace($crlf, $lf).Replace($cr, $lf)
    if ($normalized.Contains([char] 13)) { Stop-G5C 'SCRIPT_LINE_ENDING_NORMALIZATION_FAILED' }
    return $normalized
}

function Assert-NativeArguments([string[]] $Arguments) {
    foreach ($argument in $Arguments) {
        if ([string]::IsNullOrWhiteSpace($argument) -or $argument -match '\s') {
            Stop-G5C 'NATIVE_ARGUMENT_REJECTED'
        }
    }
}

function Save-Json([string] $Path, [object] $Value) {
    $json = $Value | ConvertTo-Json -Depth 10
    $temporaryPath = $Path + '.tmp'
    $backupPath = $Path + '.previous'
    if ([IO.File]::Exists($temporaryPath) -or [IO.File]::Exists($backupPath)) {
        Stop-G5C 'STATE_PERSISTENCE_RESIDUAL_PRESENT'
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
    $savedStatePath = $script:StatePath
    $savedGeneration = $script:StateWriteGeneration
    $fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ("company-os-g5c-state-contract-$PID-" + [guid]::NewGuid().ToString('N'))
    $fixturePath = Join-Path $fixtureRoot 'execution-state.json'
    try {
        [IO.Directory]::CreateDirectory($fixtureRoot) | Out-Null
        $script:StateWriteGeneration = 0
        $script:StatePath = $fixturePath
        $script:State = [ordered]@{
            schema_version=1
            helper_generation=$HelperGeneration
            status='ATTEMPT_STARTED'
            production_connection_attempted=$false
            native_process_started=$false
            local_processing_substage='ATTEMPT_INITIALIZED'
            local_state_generation=0
        }
        Save-State
        $generation1Hash = Get-FileSha256 $fixturePath
        if ((Test-Path -LiteralPath ($fixturePath + '.tmp')) -or
            (Test-Path -LiteralPath ($fixturePath + '.previous')) -or
            (Get-Content -Raw -LiteralPath $fixturePath | ConvertFrom-Json).local_state_generation -ne 1) {
            Stop-G5C 'STATE_PERSISTENCE_GENERATION_1_FAILED'
        }

        $script:State.local_processing_substage = 'REMOTE_PROCESS_PENDING'
        Save-State
        $generation2Hash = Get-FileSha256 $fixturePath
        if ($generation2Hash -eq $generation1Hash -or (Test-Path -LiteralPath ($fixturePath + '.tmp')) -or
            (Test-Path -LiteralPath ($fixturePath + '.previous')) -or
            (Get-Content -Raw -LiteralPath $fixturePath | ConvertFrom-Json).local_state_generation -ne 2) {
            Stop-G5C 'STATE_PERSISTENCE_GENERATION_2_FAILED'
        }

        $script:State.status = 'STOP'
        $script:State.local_processing_substage = 'LOCAL_CORRECTIVE_FIXTURE_STOP'
        Save-State
        $generation3Hash = Get-FileSha256 $fixturePath
        $roundTrip = Get-Content -Raw -LiteralPath $fixturePath | ConvertFrom-Json
        if ($generation3Hash -eq $generation2Hash -or (Test-Path -LiteralPath ($fixturePath + '.tmp')) -or
            (Test-Path -LiteralPath ($fixturePath + '.previous')) -or
            $roundTrip.local_state_generation -ne 3 -or $roundTrip.status -ne 'STOP') {
            Stop-G5C 'STATE_PERSISTENCE_GENERATION_3_FAILED'
        }
        return [pscustomobject]@{
            generations=3
            atomic_initial_move='PASS'
            atomic_existing_replace='PASS'
            tmp_residual=0
            backup_residual=0
            final_status='STOP'
        }
    } finally {
        $script:State = $savedState
        $script:StatePath = $savedStatePath
        $script:StateWriteGeneration = $savedGeneration
        if ([IO.Directory]::Exists($fixtureRoot)) {
            [IO.Directory]::Delete($fixtureRoot, $true)
        }
    }
}

function Invoke-CapturedProcess([string] $File, [string[]] $Arguments, [AllowNull()][string] $InputText) {
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
        if (-not $process.Start()) { Stop-G5C 'NATIVE_PROCESS_START_FAILED' }
        $script:NativeProcessStarted = $true
        $script:LocalProcessingSubstage = 'NATIVE_PROCESS_STARTED'
        if ($null -ne $script:State) {
            $script:ConnectionAttempted = $true
            $script:State.production_connection_attempted = $true
            $script:State.production_mutation_scope = 'candidate_bound_isolated_rehearsal_possible'
            $script:State.native_process_started = $true
            $script:State.local_processing_substage = $script:LocalProcessingSubstage
            Save-State
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

function Parse-SafeOutput([string] $Value) {
    if ([Text.Encoding]::UTF8.GetByteCount($Value) -gt 16384 -or
        $Value -match '/home/[A-Za-z0-9._-]+' -or
        $Value -match '(?i)(DB_PASSWORD|APP_KEY|API[_-]?KEY|PRIVATE KEY|BEARER|CREDENTIAL)') {
        Stop-G5C 'UNSAFE_REMOTE_OUTPUT'
    }
    $map = [ordered]@{}
    foreach ($line in ($Value -split '\r?\n' | Where-Object { $_ -ne '' })) {
        if ($line -notmatch '^([a-zA-Z0-9_]+)=([a-zA-Z0-9_.-]+)$') { Stop-G5C 'REMOTE_OUTPUT_CONTRACT_INVALID' }
        if ($map.Contains($Matches[1])) { Stop-G5C 'REMOTE_OUTPUT_DUPLICATE_KEY' }
        $map[$Matches[1]] = $Matches[2]
    }
    if (-not $map.Contains($ExpectedRemoteHeader)) { Stop-G5C 'REMOTE_STATUS_MISSING' }
    return $map
}

function Assert-PassEvidence([Collections.Specialized.OrderedDictionary] $Evidence) {
    $expected = [ordered]@{
        G5C_POSIX_CAPABILITY_REHEARSAL='PASS'
        release_id="ir1-$Candidate"
        rehearsal_root_binding='candidate_bound_isolated'
        actual_home_binding='exact_new_target'
        same_filesystem='true'
        posix_symlink='true'
        atomic_rename='true'
        permission_mode_0600='true'
        cleanup_state='complete'
        residual_entry_count='0'
        production_change_scope='candidate_bound_isolated_rehearsal_only'
        target_topology_changed='false'
        target_public_entry_changed='false'
        legacy_production_changed='false'
        env_changed='false'
        shared_storage_changed='false'
        database_connection='not_attempted'
        dns_ssl_change='not_attempted'
        deploy='not_attempted'
        retry_available='false'
        secret_output='false'
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }
    foreach ($key in $expected.Keys) {
        if (-not $Evidence.Contains($key) -or $Evidence[$key] -ne $expected[$key]) {
            Stop-G5C 'REMOTE_PASS_EVIDENCE_MISMATCH'
        }
    }
    if ($Evidence.Count -ne $expected.Count) { Stop-G5C 'REMOTE_PASS_EVIDENCE_UNEXPECTED_FIELD' }
}

try {
    if ($VerifyOnly -and $VerifyPersistenceOnly) { Stop-G5C 'VERIFY_MODE_CONFLICT' }
    $Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $ScriptPath = Join-Path $PSScriptRoot 'rehearse-posix-capabilities.sh'
    $G5BReceiptPath = Join-Path $Root "storage\app\release-audit\production-g5b-cross-server-reconciliation-$Candidate\g5b-reconciled-disposition.json"
    $HostBindingRoot = Join-Path $Root "storage\app\release-audit\production-g5b-new-target-binding-$Candidate"
    $HostReceiptPath = Join-Path $HostBindingRoot 'host-key-verification.json'
    $KnownHostsPath = Join-Path $HostBindingRoot 'known_hosts'
    $EvidenceRoot = Join-Path $Root "storage\app\release-audit\production-g5c-new-target-rehearsal-$Candidate"
    $ReceiptPath = Join-Path $EvidenceRoot 'capability-rehearsal.json'
    $StatePath = Join-Path $EvidenceRoot 'execution-state.json'
    $LegacyEvidenceRoot = Join-Path $Root "storage\app\release-audit\production-g5-target-$Candidate"
    $LegacyReceiptPath = Join-Path $LegacyEvidenceRoot 'capability-rehearsal.json'
    $LegacyStatePath = Join-Path $LegacyEvidenceRoot 'capability-rehearsal-corrective-2-state.json'
    $ExpectedIdentityPath = Join-Path ([Environment]::GetFolderPath('UserProfile')) ".ssh\$ExpectedIdentityLeaf"

    if (-not (Test-Path -LiteralPath $ScriptPath -PathType Leaf) -or
        (Get-FileSha256 $ScriptPath) -ne $ExpectedScriptSha256) {
        Stop-G5C 'REHEARSAL_SCRIPT_BINDING_MISMATCH'
    }
    $scriptText = Read-LfScript $ScriptPath

    if ($VerifyPersistenceOnly) {
        $persistence = Invoke-StatePersistenceSelfTest
        Write-Output 'G5C_LOCAL_STATE_PERSISTENCE_CORRECTIVE_VERIFY_ONLY=PASS'
        Write-Output "helper_generation=$HelperGeneration"
        Write-Output "generation_count=$($persistence.generations)"
        Write-Output "atomic_initial_move=$($persistence.atomic_initial_move)"
        Write-Output "atomic_existing_replace=$($persistence.atomic_existing_replace)"
        Write-Output "tmp_residual=$($persistence.tmp_residual)"
        Write-Output "backup_residual=$($persistence.backup_residual)"
        Write-Output "final_status=$($persistence.final_status)"
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_mutation=false'
        Write-Output 'existing_g5c_evidence_modified=false'
        Write-Output 'g5c_posix_capability_rehearsal=not_executed'
        exit 0
    }

    if (-not (Test-Path -LiteralPath $G5BReceiptPath -PathType Leaf) -or
        (Get-FileSha256 $G5BReceiptPath) -ne $ExpectedG5BReceiptSha256) {
        Stop-G5C 'G5B_RECONCILED_RECEIPT_BINDING_MISMATCH'
    }
    $g5b = Get-Content -Raw -LiteralPath $G5BReceiptPath | ConvertFrom-Json
    if ($g5b.candidate -ne $Candidate -or $g5b.status -ne 'PASS' -or
        $g5b.disposition -ne 'G5-B RECONCILED FORMAL PASS / G5-C POSIX CAPABILITY REHEARSAL READY' -or
        $g5b.reconciled_disposition.cross_server_identity -ne 'PASS' -or
        $g5b.reconciled_disposition.effective_legacy_separation -ne 'yes' -or
        $g5b.reconciled_disposition.target_anchor_binding -ne 'PASS' -or
        $g5b.reconciled_disposition.VHOST_DOCUMENT_ROOT_BINDING -ne 'PASS' -or
        $g5b.pending.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION -ne 'PENDING_G5_PUBLIC_ENTRY_GATE' -or
        [bool] $g5b.safety.production_connection_attempted -or
        [bool] $g5b.safety.ssh_connection_attempted -or
        [bool] $g5b.safety.http_request_attempted -or
        [bool] $g5b.safety.database_connection_attempted -or
        [bool] $g5b.safety.production_mutation) {
        Stop-G5C 'G5B_RECONCILED_DISPOSITION_MISMATCH'
    }

    if (-not (Test-Path -LiteralPath $HostReceiptPath -PathType Leaf) -or
        (Get-FileSha256 $HostReceiptPath) -ne $ExpectedHostReceiptSha256) {
        Stop-G5C 'HOST_RECEIPT_BINDING_MISMATCH'
    }
    $hostReceipt = Get-Content -Raw -LiteralPath $HostReceiptPath | ConvertFrom-Json
    if ($hostReceipt.status -ne 'PASS' -or $hostReceipt.host -ne $ExpectedHost -or
        [string] $hostReceipt.port -ne $ExpectedPort -or $hostReceipt.algorithm -ne 'ssh-ed25519' -or
        $hostReceipt.panel_fingerprint -ne $ExpectedHostKeyFingerprint -or
        $hostReceipt.observed_fingerprint -ne $ExpectedHostKeyFingerprint -or
        -not [bool] $hostReceipt.fingerprint_match -or [bool] $hostReceipt.production_mutation) {
        Stop-G5C 'HOST_RECEIPT_DISPOSITION_MISMATCH'
    }

    $ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue
    $keygen = Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $keygen) { Stop-G5C 'OPENSSH_CLIENT_UNAVAILABLE' }
    if (-not (Test-Path -LiteralPath $ExpectedIdentityPath -PathType Leaf)) { Stop-G5C 'SSH_IDENTITY_MISSING' }
    $identityResult = Invoke-CapturedProcess $keygen.Source @('-lf', $ExpectedIdentityPath) $null
    if ($identityResult.ExitCode -ne 0 -or
        $identityResult.Stdout -notmatch [regex]::Escape($ExpectedIdentityFingerprint)) {
        Stop-G5C 'SSH_IDENTITY_FINGERPRINT_MISMATCH'
    }
    if (-not (Test-Path -LiteralPath $KnownHostsPath -PathType Leaf) -or
        (Get-FileSha256 $KnownHostsPath) -ne $ExpectedKnownHostsSha256) {
        Stop-G5C 'KNOWN_HOSTS_BINDING_MISMATCH'
    }
    $lookup = "[$ExpectedHost]:$ExpectedPort"
    $knownResult = Invoke-CapturedProcess $keygen.Source @('-F', $lookup, '-f', $KnownHostsPath) $null
    if ($knownResult.ExitCode -ne 0) { Stop-G5C 'HOST_KEY_NOT_REGISTERED' }
    $knownKeyLines = (($knownResult.Stdout -split '\r?\n' | Where-Object { $_ -ne '' -and $_ -notmatch '^#' }) -join [Environment]::NewLine)
    $knownFingerprint = Invoke-CapturedProcess $keygen.Source @('-lf', '-') $knownKeyLines
    if ($knownFingerprint.ExitCode -ne 0 -or
        $knownFingerprint.Stdout -notmatch [regex]::Escape($ExpectedHostKeyFingerprint)) {
        Stop-G5C 'HOST_KEY_FINGERPRINT_MISMATCH'
    }

    $configArguments = @(
        '-G', '-p', $ExpectedPort, '-l', $ExpectedUser, '-i', $ExpectedIdentityPath,
        '-o', 'IdentitiesOnly=yes', '-o', "UserKnownHostsFile=$KnownHostsPath",
        '-o', 'StrictHostKeyChecking=yes', '-o', 'HostKeyAlgorithms=ssh-ed25519', $ExpectedHost
    )
    $configResult = Invoke-CapturedProcess $ssh.Source $configArguments $null
    if ($configResult.ExitCode -ne 0) { Stop-G5C 'SSH_CONFIG_UNAVAILABLE' }
    $config = @{}
    foreach ($line in ($configResult.Stdout -split '\r?\n')) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) { $config[$parts[0].ToLowerInvariant()] = $parts[1] }
    }
    foreach ($key in @('hostname', 'user', 'port', 'identityfile', 'identitiesonly')) {
        if (-not $config.ContainsKey($key)) { Stop-G5C 'SSH_CONFIG_INCOMPLETE' }
    }
    if ($config.hostname -ne $ExpectedHost -or $config.user -ne $ExpectedUser -or
        $config.port -ne $ExpectedPort -or
        (Split-Path -Leaf $config.identityfile.Trim('"')) -ne $ExpectedIdentityLeaf -or
        $config.identitiesonly -ne 'yes') {
        Stop-G5C 'SSH_BINDING_MISMATCH'
    }
    foreach ($key in @('remotecommand', 'localcommand', 'proxycommand', 'proxyjump')) {
        if ($config.ContainsKey($key) -and -not [string]::IsNullOrWhiteSpace($config[$key]) -and
            $config[$key] -ne 'none') {
            Stop-G5C 'SSH_AUTOMATIC_COMMAND_FORBIDDEN'
        }
    }

    if ((Test-Path -LiteralPath $EvidenceRoot) -or
        (Test-Path -LiteralPath $LegacyReceiptPath) -or
        (Test-Path -LiteralPath $LegacyStatePath)) {
        Stop-G5C 'G5C_ATTEMPT_ALREADY_RECORDED'
    }

    if ($VerifyOnly) {
        $fixture = Parse-SafeOutput "G5C_POSIX_CAPABILITY_REHEARSAL=STOP`nsafe_error_code=VERIFY_ONLY_FIXTURE`n"
        if ($fixture.G5C_POSIX_CAPABILITY_REHEARSAL -ne 'STOP') { Stop-G5C 'SAFE_OUTPUT_PARSER_SELF_TEST_FAILED' }
        Write-Output 'G5C_NEW_TARGET_REHEARSAL_VERIFY_ONLY=PASS'
        Write-Output "candidate=$Candidate"
        Write-Output "helper_generation=$HelperGeneration"
        Write-Output "script_sha256=$ExpectedScriptSha256"
        Write-Output "g5b_receipt_sha256=$ExpectedG5BReceiptSha256"
        Write-Output "ssh_host=$ExpectedHost"
        Write-Output "ssh_user=$ExpectedUser"
        Write-Output "ssh_port=$ExpectedPort"
        Write-Output "identity_fingerprint=$ExpectedIdentityFingerprint"
        Write-Output "host_key_fingerprint=$ExpectedHostKeyFingerprint"
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_mutation=false'
        Write-Output 'g5c_posix_capability_rehearsal=not_executed'
        exit 0
    }

    New-Item -ItemType Directory -Path $EvidenceRoot | Out-Null
    $State = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='ATTEMPT_STARTED'
        g5b_receipt_sha256=$ExpectedG5BReceiptSha256; script_sha256=$ExpectedScriptSha256
        ssh_binding='explicit_new_target'; ssh_host=$ExpectedHost; ssh_user=$ExpectedUser; ssh_port=[int] $ExpectedPort
        production_connection_attempted=$false; production_mutation_scope='none'; native_process_started=$false
        remote_exit_code=$null; stdout_sha256=$null; stdout_bytes=0; stderr_sha256=$null; stderr_bytes=0
        ssh_authentication='unknown'; remote_shell='unknown'; remote_contract='unknown'; remote_evidence=$null
        local_processing_substage='ATTEMPT_INITIALIZED'; cleanup_state='unknown'; residual_entry_count='unknown'
        raw_output_stored=$false; retry_performed=$false; safe_error_code=$null
        local_state_generation=0
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
    }
    Save-State

    $NativeProcessStarted = $false
    $arguments = @(
        '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'NumberOfPasswordPrompts=0',
        '-o', 'ConnectionAttempts=1', '-o', 'ConnectTimeout=10', '-o', 'ClearAllForwardings=yes',
        '-o', 'LogLevel=ERROR', '-o', 'HostKeyAlgorithms=ssh-ed25519',
        '-o', "UserKnownHostsFile=$KnownHostsPath", '-p', $ExpectedPort, '-l', $ExpectedUser,
        '-i', $ExpectedIdentityPath, '-T', $ExpectedHost,
        'sh', '-s', '--', $ExpectedConfirmation
    )
    Assert-NativeArguments $arguments
    $FailureStage = 'PRODUCTION_ISOLATED_POSIX_CAPABILITY_REHEARSAL'
    $LocalProcessingSubstage = 'REMOTE_PROCESS_PENDING'
    $State.local_processing_substage = $LocalProcessingSubstage
    Save-State

    $result = Invoke-CapturedProcess $ssh.Source $arguments $scriptText
    $RemoteExitCode = $result.ExitCode
    $LocalProcessingSubstage = 'REMOTE_RESULT_CAPTURED'
    $State.remote_exit_code = $RemoteExitCode
    $State.stdout_sha256 = Get-TextSha256 $result.Stdout
    $State.stdout_bytes = [Text.Encoding]::UTF8.GetByteCount($result.Stdout)
    $State.stderr_sha256 = Get-TextSha256 $result.Stderr
    $State.stderr_bytes = [Text.Encoding]::UTF8.GetByteCount($result.Stderr)
    $State.local_processing_substage = $LocalProcessingSubstage
    Save-State

    $ParsedRemoteEvidence = Parse-SafeOutput $result.Stdout
    $State.ssh_authentication = 'established'
    $State.remote_shell = 'established'
    $State.remote_contract = if ($ParsedRemoteEvidence[$ExpectedRemoteHeader] -eq 'PASS') { 'complete' } else { 'reported_stop' }
    $State.remote_evidence = $ParsedRemoteEvidence
    if ($ParsedRemoteEvidence.Contains('cleanup_state')) { $State.cleanup_state = $ParsedRemoteEvidence.cleanup_state }
    if ($ParsedRemoteEvidence.Contains('residual_entry_count')) { $State.residual_entry_count = $ParsedRemoteEvidence.residual_entry_count }
    Save-State

    if ($ParsedRemoteEvidence[$ExpectedRemoteHeader] -ne 'PASS') { Stop-G5C 'REMOTE_REPORTED_STOP' }
    if ($RemoteExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($result.Stderr)) {
        Stop-G5C 'REMOTE_STEP_FAILED'
    }
    Assert-PassEvidence $ParsedRemoteEvidence

    $receipt = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='PASS'
        g5b_receipt_sha256=$ExpectedG5BReceiptSha256; script_sha256=$ExpectedScriptSha256
        ssh=[ordered]@{
            binding='explicit_new_target'; alias=$null; host=$ExpectedHost; user=$ExpectedUser; port=[int] $ExpectedPort
            identity_fingerprint=$ExpectedIdentityFingerprint; host_key_algorithm='ssh-ed25519'
            host_key_fingerprint=$ExpectedHostKeyFingerprint; authentication='established'; remote_shell='established'
        }
        operation=[ordered]@{
            production_connection_attempted=$true
            production_mutation_scope='candidate_bound_isolated_rehearsal_completed_and_cleaned'
            target_topology_changed=$false; target_public_entry_changed=$false; legacy_production_changed=$false
            env_changed=$false; shared_storage_changed=$false; database_connection='not_attempted'
            dns_ssl_change='not_attempted'; deploy='not_attempted'; retry_performed=$false
        }
        stream=[ordered]@{
            remote_exit_code=$RemoteExitCode; stdout_sha256=(Get-TextSha256 $result.Stdout)
            stdout_bytes=[Text.Encoding]::UTF8.GetByteCount($result.Stdout)
            stderr_sha256=(Get-TextSha256 $result.Stderr); stderr_bytes=[Text.Encoding]::UTF8.GetByteCount($result.Stderr)
            raw_output_stored=$false
        }
        evidence=$ParsedRemoteEvidence
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
        deploy_authorized=$false
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }
    Save-Json $ReceiptPath $receipt
    $State.status = 'PASS'
    $State.production_mutation_scope = 'candidate_bound_isolated_rehearsal_completed_and_cleaned'
    $State.local_processing_substage = 'COMPLETE'
    $State.safe_error_code = $null
    Save-State

    Write-Output 'G5C_POSIX_CAPABILITY_REHEARSAL=PASS'
    Write-Output 'production_connection_attempted=true'
    Write-Output 'production_mutation=candidate_bound_isolated_rehearsal_completed_and_cleaned'
    Write-Output 'cleanup_state=complete'
    Write-Output 'residual_entry_count=0'
    Write-Output 'retry_available=false'
    Write-Output 'deploy_authorized=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
} catch {
    $safeCode = Get-SafeErrorCode $_.Exception
    $statePersistenceStatus = 'not_applicable'
    if ($null -ne $State) {
        $State.status = 'STOP'
        $State.safe_error_code = $safeCode
        $State.production_connection_attempted = $ConnectionAttempted
        $State.native_process_started = $NativeProcessStarted
        $State.remote_exit_code = $RemoteExitCode
        $State.local_processing_substage = $LocalProcessingSubstage
        if ($null -ne $ParsedRemoteEvidence) {
            $State.remote_evidence = $ParsedRemoteEvidence
            if ($ParsedRemoteEvidence.Contains('cleanup_state')) { $State.cleanup_state = $ParsedRemoteEvidence.cleanup_state }
            if ($ParsedRemoteEvidence.Contains('residual_entry_count')) { $State.residual_entry_count = $ParsedRemoteEvidence.residual_entry_count }
        }
        try {
            Save-State
            $statePersistenceStatus = 'saved'
        } catch {
            $statePersistenceStatus = 'failed'
        }
    }
    Write-Output 'G5C_POSIX_CAPABILITY_REHEARSAL=STOP'
    Write-Output "safe_error_code=$safeCode"
    Write-Output "failure_stage=$FailureStage"
    Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "native_process_started=$($NativeProcessStarted.ToString().ToLowerInvariant())"
    Write-Output "production_mutation=$(if ($ConnectionAttempted) { 'isolated_rehearsal_unknown_review_evidence' } else { 'false' })"
    Write-Output "local_state_persistence=$statePersistenceStatus"
    Write-Output 'retry_performed=false'
    Write-Output 'deploy_authorized=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}
