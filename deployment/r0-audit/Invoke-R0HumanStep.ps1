[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet(2, 3, 4, 5, 6)]
    [int] $Step,

    [switch] $VerifyOnly,

    [switch] $VerifyLocalPreconditionsOnly,

    [switch] $InspectStep2RemoteStateOnly,

    [switch] $InspectStep2RemoteContentsOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:R0Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$script:R0BundleId = '5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0'
$script:R0BundleSha256 = 'a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d'
$script:R0SshAlias = 'company-os-production'
$script:R0IdentityFile = 'codex-company-os-production'
$script:R0ProtocolVersion = 1
$script:R0ExecutionGeneration = 'corrective-2'
$script:R0CurrentStep = $Step
$script:R0State = $null
$script:R0StatePath = $null
$script:R0FailureStage = 'BOOTSTRAP'
$script:R0ProductionConnectionAttempted = $false
$script:R0InspectionEvidencePath = $null
$script:R0InspectionEvidence = $null

function Write-R0Stop {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)

    Write-Output "R0_STEP_$script:R0CurrentStep=STOP"
    Write-Output "safe_error_code=$SafeErrorCode"
    Write-Output 'retry_performed=false'
    Write-Output ('failure_stage=' + $script:R0FailureStage)
    Write-Output ('production_connection_attempted=' + $script:R0ProductionConnectionAttempted.ToString().ToLowerInvariant())
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
}

function Stop-R0 {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)

    throw [System.InvalidOperationException]::new($SafeErrorCode)
}

function Get-JstTimestamp {
    return [DateTimeOffset]::UtcNow.ToOffset([TimeSpan]::FromHours(9)).ToString('o')
}

function Get-Sha256Text {
    param([AllowEmptyString()][string] $Value)

    $bytes = [Text.Encoding]::UTF8.GetBytes($Value)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $sha.Dispose()
    }
}

function Get-RepositoryRoot {
    $deploymentDirectory = Split-Path -Parent $PSScriptRoot
    return Split-Path -Parent $deploymentDirectory
}

function Get-HelperSha256 {
    return (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Write-SanitizedFailureReceipt {
    param(
        [Parameter(Mandatory = $true)][string] $RepositoryRoot,
        [Parameter(Mandatory = $true)][string] $SafeErrorCode,
        [Parameter(Mandatory = $true)][string] $ExceptionType,
        [AllowEmptyString()][string] $ExceptionMessage
    )

    $safeExceptionType = if ($ExceptionType -match '^[A-Za-z0-9_.]+$') {
        $ExceptionType
    }
    else {
        'UnknownExceptionType'
    }
    $receiptPath = Join-Path $RepositoryRoot (
        'storage\app\release-audit\r0-human-helper-failure-' + $script:R0Candidate + '.json'
    )
    $receipt = [pscustomobject]@{
        schema_version = 1
        candidate = $script:R0Candidate
        execution_generation = $script:R0ExecutionGeneration
        step = $script:R0CurrentStep
        status = 'STOP'
        safe_error_code = $SafeErrorCode
        failure_stage = $script:R0FailureStage
        exception_type = $safeExceptionType
        exception_message_sha256 = Get-Sha256Text $ExceptionMessage
        production_connection_attempted = $script:R0ProductionConnectionAttempted
        raw_exception_stored = $false
        recorded_at_jst = Get-JstTimestamp
    }
    $json = $receipt | ConvertTo-Json -Depth 4
    $temporaryPath = $receiptPath + '.tmp'
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $receiptPath -Force
}

function Save-R0State {
    if ($null -eq $script:R0State -or [string]::IsNullOrWhiteSpace($script:R0StatePath)) {
        Stop-R0 'LOCAL_STATE_NOT_INITIALIZED'
    }

    $json = $script:R0State | ConvertTo-Json -Depth 8
    $temporaryPath = "$script:R0StatePath.tmp"
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $script:R0StatePath -Force
}

function Save-R0InspectionEvidence {
    if ($null -eq $script:R0InspectionEvidence -or
        [string]::IsNullOrWhiteSpace($script:R0InspectionEvidencePath)) {
        Stop-R0 'LOCAL_INSPECTION_STATE_NOT_INITIALIZED'
    }

    $json = $script:R0InspectionEvidence | ConvertTo-Json -Depth 6
    $temporaryPath = $script:R0InspectionEvidencePath + '.tmp'
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $script:R0InspectionEvidencePath -Force
}

function Read-R0State {
    param([Parameter(Mandatory = $true)][string] $Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        Stop-R0 'REQUIRED_LOCAL_STATE_MISSING'
    }

    try {
        $state = Get-Content -Raw -LiteralPath $Path | ConvertFrom-Json
    }
    catch {
        Stop-R0 'LOCAL_STATE_INVALID'
    }

    if ($state.protocol_version -ne $script:R0ProtocolVersion -or
        $state.candidate -ne $script:R0Candidate -or
        $state.bundle_id -ne $script:R0BundleId) {
        Stop-R0 'LOCAL_STATE_IDENTITY_MISMATCH'
    }

    return $state
}

function Assert-StepEligibility {
    param(
        [Parameter(Mandatory = $true)] $State,
        [Parameter(Mandatory = $true)][int] $RequestedStep
    )

    $existingAttempt = @($State.attempts | Where-Object { [int] $_.step -eq $RequestedStep })
    if ($existingAttempt.Count -gt 0) {
        Stop-R0 'STEP_RETRY_FORBIDDEN'
    }

    $requiredPreviousStep = $RequestedStep - 1
    if ($RequestedStep -gt 2 -and
        ([int] $State.last_step -ne $requiredPreviousStep -or
         [string] $State.last_status -ne 'PASS')) {
        Stop-R0 'PREVIOUS_STEP_NOT_PASS'
    }
}

function Start-R0Attempt {
    param(
        [Parameter(Mandatory = $true)][string] $ExecutionRoot,
        [Parameter(Mandatory = $true)][string] $StatePath,
        [Parameter(Mandatory = $true)][string] $HelperSha256
    )

    if ($Step -eq 2) {
        if (Test-Path -LiteralPath $ExecutionRoot) {
            Stop-R0 'STEP_2_LOCAL_EXECUTION_STATE_ALREADY_EXISTS'
        }

        New-Item -ItemType Directory -Path $ExecutionRoot | Out-Null
        $script:R0StatePath = $StatePath
        $script:R0State = [pscustomobject]@{
            protocol_version = $script:R0ProtocolVersion
            candidate = $script:R0Candidate
            bundle_id = $script:R0BundleId
            created_at_jst = Get-JstTimestamp
            last_step = 0
            last_status = 'INITIALIZED'
            attempts = @()
        }
    }
    else {
        $script:R0StatePath = $StatePath
        $script:R0State = Read-R0State -Path $StatePath
    }

    Assert-StepEligibility -State $script:R0State -RequestedStep $Step

    $attempt = [pscustomobject]@{
        step = $Step
        status = 'ATTEMPT_STARTED'
        started_at_jst = Get-JstTimestamp
        completed_at_jst = $null
        helper_sha256 = $HelperSha256
        safe_error_code = $null
        remote_exit_code = $null
        stderr_sha256 = $null
        stderr_bytes = 0
    }
    $script:R0State.attempts = @($script:R0State.attempts) + @($attempt)
    $script:R0State.last_step = $Step
    $script:R0State.last_status = 'ATTEMPT_STARTED'
    Save-R0State
}

function Complete-R0Attempt {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('PASS', 'STOP')][string] $Status,
        [string] $SafeErrorCode,
        [Nullable[int]] $RemoteExitCode,
        [string] $Stderr
    )

    if ($null -eq $script:R0State) {
        return
    }

    $attempt = @($script:R0State.attempts | Where-Object { [int] $_.step -eq $Step })[-1]
    $attempt.status = $Status
    $attempt.completed_at_jst = Get-JstTimestamp
    $attempt.safe_error_code = if ($Status -eq 'STOP') { $SafeErrorCode } else { $null }
    $attempt.remote_exit_code = $RemoteExitCode
    $attempt.stderr_sha256 = if ([string]::IsNullOrEmpty($Stderr)) { $null } else { Get-Sha256Text $Stderr }
    $attempt.stderr_bytes = if ([string]::IsNullOrEmpty($Stderr)) { 0 } else { [Text.Encoding]::UTF8.GetByteCount($Stderr) }
    $script:R0State.last_step = $Step
    $script:R0State.last_status = $Status
    Save-R0State
}

function Invoke-CapturedProcess {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [Parameter(Mandatory = $true)][string[]] $Arguments,
        [AllowNull()][string] $StandardInput
    )

    $stdoutPath = [IO.Path]::GetTempFileName()
    $stderrPath = [IO.Path]::GetTempFileName()
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        # Windows PowerShell 5.1 can promote native stderr to a terminating
        # RemoteException when the caller uses ErrorActionPreference=Stop.
        # Capture stderr and decide from ExitCode instead of allowing that
        # promotion to bypass the sanitized failure contract.
        $ErrorActionPreference = 'Continue'
        if ($null -eq $StandardInput) {
            & $FilePath @Arguments 1> $stdoutPath 2> $stderrPath
        }
        else {
            $StandardInput | & $FilePath @Arguments 1> $stdoutPath 2> $stderrPath
        }
        $exitCode = $LASTEXITCODE
        return [pscustomobject]@{
            ExitCode = $exitCode
            Stdout = [IO.File]::ReadAllText($stdoutPath)
            Stderr = [IO.File]::ReadAllText($stderrPath)
        }
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
        Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}

function Get-LocalPreconditions {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $script:R0FailureStage = 'LOCAL_BUNDLE_INTEGRITY'
    $bundleName = "ir1-r0-audit-bundle-$script:R0Candidate.tar.gz"
    $bundlePath = Join-Path $RepositoryRoot "storage\app\release-audit\$bundleName"
    if (-not (Test-Path -LiteralPath $bundlePath -PathType Leaf)) {
        Stop-R0 'AUDIT_BUNDLE_MISSING'
    }
    if ((Get-FileHash -LiteralPath $bundlePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $script:R0BundleSha256) {
        Stop-R0 'AUDIT_BUNDLE_HASH_MISMATCH'
    }

    $script:R0FailureStage = 'LOCAL_OPENSSH_TOOL_DISCOVERY'
    $ssh = Get-Command 'ssh.exe' -ErrorAction SilentlyContinue
    $scp = Get-Command 'scp.exe' -ErrorAction SilentlyContinue
    $sshKeygen = Get-Command 'ssh-keygen.exe' -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $scp -or $null -eq $sshKeygen) {
        Stop-R0 'OPENSSH_CLIENT_UNAVAILABLE'
    }

    $script:R0FailureStage = 'LOCAL_SSH_CONFIG_PARSE'
    $configResult = Invoke-CapturedProcess -FilePath $ssh.Source -Arguments @('-G', $script:R0SshAlias) -StandardInput $null
    if ($configResult.ExitCode -ne 0) {
        Stop-R0 'SSH_CONFIG_UNAVAILABLE'
    }
    $config = @{}
    foreach ($line in ($configResult.Stdout -split "`r?`n")) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) {
            $config[$parts[0].ToLowerInvariant()] = $parts[1]
        }
    }

    $script:R0FailureStage = 'LOCAL_SSH_CONFIG_CONTRACT'
    foreach ($required in @('hostname', 'user', 'port', 'identityfile')) {
        if (-not $config.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($config[$required])) {
            Stop-R0 'SSH_CONFIG_INCOMPLETE'
        }
    }
    if ((Split-Path -Leaf $config.identityfile.Trim('"')) -ne $script:R0IdentityFile -or
        $config.identitiesonly -ne 'yes') {
        Stop-R0 'SSH_IDENTITY_CONTRACT_MISMATCH'
    }
    foreach ($directive in @('remotecommand', 'localcommand', 'proxycommand', 'proxyjump')) {
        if ($config.ContainsKey($directive) -and $config[$directive] -ne 'none') {
            Stop-R0 'SSH_AUTOMATIC_COMMAND_FORBIDDEN'
        }
    }

    $script:R0FailureStage = 'LOCAL_KNOWN_HOSTS_FILE'
    $knownHosts = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHosts -PathType Leaf)) {
        Stop-R0 'KNOWN_HOSTS_MISSING'
    }
    $lookup = "[$($config.hostname)]:$($config.port)"
    $script:R0FailureStage = 'LOCAL_HOST_KEY_LOOKUP'
    $knownResult = Invoke-CapturedProcess -FilePath $sshKeygen.Source -Arguments @('-F', $lookup, '-f', $knownHosts) -StandardInput $null
    if ($knownResult.ExitCode -ne 0) {
        Stop-R0 'HOST_KEY_NOT_REGISTERED'
    }
    $knownTypes = @($knownResult.Stdout -split "`r?`n" |
        Where-Object { $_ -and -not $_.StartsWith('#') } |
        ForEach-Object { ($_ -split '\s+')[1] } |
        Sort-Object -Unique)
    $script:R0FailureStage = 'LOCAL_HOST_KEY_CONTRACT'
    if (($knownTypes -join ',') -ne 'ecdsa-sha2-nistp256,ssh-ed25519,ssh-rsa') {
        Stop-R0 'HOST_KEY_CONTRACT_MISMATCH'
    }

    $script:R0FailureStage = 'LOCAL_PRECONDITIONS_COMPLETE'
    return [pscustomobject]@{
        BundleName = $bundleName
        BundlePath = $bundlePath
        SshPath = $ssh.Source
        ScpPath = $scp.Source
    }
}

function Get-CommonSshArguments {
    return @(
        '-o', 'BatchMode=yes',
        '-o', 'StrictHostKeyChecking=yes',
        '-o', 'NumberOfPasswordPrompts=0',
        '-o', 'ConnectionAttempts=1',
        '-o', 'ConnectTimeout=10',
        '-o', 'ClearAllForwardings=yes',
        '-o', 'LogLevel=ERROR'
    )
}

function Invoke-RemoteScript {
    param(
        [Parameter(Mandatory = $true)][string] $SshPath,
        [Parameter(Mandatory = $true)][string] $Script
    )

    $arguments = @(Get-CommonSshArguments) + @('-T', $script:R0SshAlias, 'sh', '-s')
    return Invoke-CapturedProcess -FilePath $SshPath -Arguments $arguments -StandardInput $Script
}

function Assert-FixedSuccessOutput {
    param(
        [Parameter(Mandatory = $true)][string] $Actual,
        [Parameter(Mandatory = $true)][string[]] $ExpectedLines
    )

    $actualLines = @($Actual -split "`r?`n" | Where-Object { $_ -ne '' })
    if (($actualLines -join "`n") -ne ($ExpectedLines -join "`n")) {
        Stop-R0 'REMOTE_OUTPUT_CONTRACT_MISMATCH'
    }
}

function Test-SafeJsonEvidence {
    param([Parameter(Mandatory = $true)][string] $Value)

    if ([Text.Encoding]::UTF8.GetByteCount($Value) -gt 2097152) {
        return $false
    }
    foreach ($pattern in @(
        '/home/[A-Za-z0-9._-]+',
        '(?i)-----BEGIN [A-Z ]*PRIVATE KEY-----',
        '(?i)(\x22)?(DB_PASSWORD|APP_KEY|API[_-]?KEY|AUTHORIZATION|BEARER|CREDENTIAL)(\x22)?\s*[:=]'
    )) {
        if ($Value -match $pattern) {
            return $false
        }
    }
    try {
        $null = $Value | ConvertFrom-Json
    }
    catch {
        return $false
    }
    return $true
}

function Invoke-HelperSelfTest {
    $retryState = [pscustomobject]@{
        attempts = @([pscustomobject]@{ step = 2 })
        last_step = 2
        last_status = 'PASS'
    }
    try {
        Assert-StepEligibility -State $retryState -RequestedStep 2
        Stop-R0 'SELF_TEST_RETRY_GUARD_NOT_TRIGGERED'
    }
    catch {
        if ($_.Exception.Message -ne 'STEP_RETRY_FORBIDDEN') {
            Stop-R0 'SELF_TEST_RETRY_GUARD_FAILED'
        }
    }

    $sequenceState = [pscustomobject]@{
        attempts = @()
        last_step = 1
        last_status = 'PASS'
    }
    try {
        Assert-StepEligibility -State $sequenceState -RequestedStep 3
        Stop-R0 'SELF_TEST_SEQUENCE_GUARD_NOT_TRIGGERED'
    }
    catch {
        if ($_.Exception.Message -ne 'PREVIOUS_STEP_NOT_PASS') {
            Stop-R0 'SELF_TEST_SEQUENCE_GUARD_FAILED'
        }
    }

    $safeEvidence = @{ status = 'PASS' } | ConvertTo-Json -Compress
    $secretEvidence = @{ DB_PASSWORD = 'secret-output-canary' } | ConvertTo-Json -Compress
    if (-not (Test-SafeJsonEvidence -Value $safeEvidence)) {
        Stop-R0 'SELF_TEST_SAFE_EVIDENCE_REJECTED'
    }
    if (Test-SafeJsonEvidence -Value $secretEvidence) {
        Stop-R0 'SELF_TEST_SECRET_EVIDENCE_ACCEPTED'
    }

    $nativeStderr = Invoke-CapturedProcess `
        -FilePath $env:ComSpec `
        -Arguments @('/d', '/c', 'echo r0-native-stderr-canary 1>&2') `
        -StandardInput $null
    if ($nativeStderr.ExitCode -ne 0 -or
        $nativeStderr.Stdout -ne '' -or
        -not $nativeStderr.Stderr.Contains('r0-native-stderr-canary')) {
        Stop-R0 'SELF_TEST_NATIVE_STDERR_CAPTURE_FAILED'
    }

    $step2Script = Get-Step2Script
    foreach ($required in @('test ! -e ', 'R0_STEP_2=PASS')) {
        if (-not $step2Script.Contains($required)) {
            Stop-R0 'SELF_TEST_STEP_2_SCOPE_INCOMPLETE'
        }
    }
    $step2Lines = @($step2Script -split '\r?\n' | ForEach-Object { $_.Trim() })
    $mkdirLines = @($step2Lines | Where-Object { $_.StartsWith('mkdir ') })
    if ($mkdirLines.Count -ne 2 -or
        -not ($mkdirLines | Where-Object { $_.Contains('$AUDIT_ROOT') }) -or
        -not ($mkdirLines | Where-Object { $_.Contains('$AUDIT_DIR') })) {
        Stop-R0 'SELF_TEST_STEP_2_SCOPE_INCOMPLETE'
    }
    foreach ($prefix in @('rm ', 'chmod ', 'chown ', 'ln ', 'mv ', 'cp ', 'touch ')) {
        if ($step2Lines | Where-Object { $_.StartsWith($prefix) }) {
            Stop-R0 'SELF_TEST_STEP_2_SCOPE_EXPANDED'
        }
    }

    $inspectionScript = Get-Step2StateInspectionScript
    foreach ($required in @(
        'ssh_authentication=established',
        'home_identity=pass',
        'production_change_scope=none_read_only_state_inspection'
    )) {
        if (-not $inspectionScript.Contains($required)) {
            Stop-R0 'SELF_TEST_STEP_2_INSPECTION_INCOMPLETE'
        }
    }
    $inspectionLines = @($inspectionScript -split '\r?\n' | ForEach-Object { $_.Trim() })
    foreach ($prefix in @('mkdir ', 'rm ', 'chmod ', 'chown ', 'ln ', 'mv ', 'cp ', 'touch ')) {
        if ($inspectionLines | Where-Object { $_.StartsWith($prefix) }) {
            Stop-R0 'SELF_TEST_STEP_2_INSPECTION_MUTATION_PRESENT'
        }
    }

    $contentInspectionScript = Get-Step2ContentInspectionScript
    foreach ($required in @(
        'bundle_archive_integrity=',
        'candidate_unexpected_entry_count=',
        'production_change_scope=none_read_only_content_inspection'
    )) {
        if (-not $contentInspectionScript.Contains($required)) {
            Stop-R0 'SELF_TEST_STEP_2_CONTENT_INSPECTION_INCOMPLETE'
        }
    }
    $contentInspectionLines = @($contentInspectionScript -split '\r?\n' | ForEach-Object { $_.Trim() })
    foreach ($prefix in @('mkdir ', 'rm ', 'chmod ', 'chown ', 'ln ', 'mv ', 'cp ', 'touch ', 'tar ')) {
        if ($contentInspectionLines | Where-Object { $_.StartsWith($prefix) }) {
            Stop-R0 'SELF_TEST_STEP_2_CONTENT_INSPECTION_MUTATION_PRESENT'
        }
    }
}

function Get-Step2Script {
    return @'
set -eu
ACTUAL_HOME="$(cd "$HOME" && pwd -P)"
case "$ACTUAL_HOME" in
  /home/[A-Za-z0-9._-]*) ;;
  *) exit 41 ;;
esac
test -d "$ACTUAL_HOME/rise-gate.com/rise-gate-os"
test -d "$ACTUAL_HOME/rise-gate.com/public_html/os.rise-gate.com"
AUDIT_ROOT="$ACTUAL_HOME/.ir1-r0-audit"
AUDIT_DIR="$AUDIT_ROOT/924af91188cc60d33ff87c91b94ecc1d539566e6"
umask 077
test ! -e "$AUDIT_ROOT"
mkdir "$AUDIT_ROOT"
mkdir "$AUDIT_DIR"
test -d "$AUDIT_ROOT"
test ! -L "$AUDIT_ROOT"
test -d "$AUDIT_DIR"
test ! -L "$AUDIT_DIR"
printf 'R0_STEP_2=PASS\n'
printf 'production_change_scope=isolated_audit_directories_only\n'
'@
}

function Get-Step3PrecheckScript {
    return @'
set -eu
ACTUAL_HOME="$(cd "$HOME" && pwd -P)"
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac
AUDIT_DIR="$ACTUAL_HOME/.ir1-r0-audit/924af91188cc60d33ff87c91b94ecc1d539566e6"
test -d "$AUDIT_DIR"
test ! -L "$AUDIT_DIR"
test ! -e "$AUDIT_DIR/ir1-r0-audit-bundle-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz"
test ! -e "$AUDIT_DIR/bundle"
printf 'R0_STEP_3_PRECHECK=PASS\n'
'@
}

function Get-Step2StateInspectionScript {
    return @'
set -eu
ACTUAL_HOME=$(cd -- ${HOME} && pwd -P)
case ${ACTUAL_HOME} in
  /home/[A-Za-z0-9._-]*) ;;
  *) exit 41 ;;
esac
classify_path() {
  if test -L ${1}; then
    printf 'symlink'
  elif test -d ${1}; then
    printf 'directory'
  elif test -e ${1}; then
    printf 'other'
  else
    printf 'absent'
  fi
}
if test -d ${ACTUAL_HOME}/rise-gate.com/rise-gate-os; then
  APPLICATION_ROOT=present
else
  APPLICATION_ROOT=missing
fi
if test -d ${ACTUAL_HOME}/rise-gate.com/public_html/os.rise-gate.com; then
  PUBLIC_ROOT=present
else
  PUBLIC_ROOT=missing
fi
AUDIT_ROOT=${ACTUAL_HOME}/.ir1-r0-audit
CANDIDATE_DIR=${AUDIT_ROOT}/924af91188cc60d33ff87c91b94ecc1d539566e6
AUDIT_ROOT_STATE=$(classify_path ${AUDIT_ROOT})
if test ${AUDIT_ROOT_STATE} = directory; then
  CANDIDATE_STATE=$(classify_path ${CANDIDATE_DIR})
else
  CANDIDATE_STATE=not_inspected
fi
printf 'R0_STEP_2_STATE_INSPECTION=PASS\n'
printf 'ssh_authentication=established\n'
printf 'home_identity=pass\n'
printf 'legacy_application_root=%s\n' ${APPLICATION_ROOT}
printf 'legacy_public_root=%s\n' ${PUBLIC_ROOT}
printf 'audit_root=%s\n' ${AUDIT_ROOT_STATE}
printf 'candidate_directory=%s\n' ${CANDIDATE_STATE}
printf 'production_change_scope=none_read_only_state_inspection\n'
'@
}

function Get-Step4Script {
    return @'
set -eu
ACTUAL_HOME="$(cd "$HOME" && pwd -P)"
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac
AUDIT_DIR="$ACTUAL_HOME/.ir1-r0-audit/924af91188cc60d33ff87c91b94ecc1d539566e6"
BUNDLE_NAME="ir1-r0-audit-bundle-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz"
EXPECTED_HASH="a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d"
cd "$AUDIT_DIR"
printf '%s  %s\n' "$EXPECTED_HASH" "$BUNDLE_NAME" | sha256sum --check --strict - >/dev/null
test ! -e bundle
umask 077
mkdir bundle
tar --extract --gzip --file "$BUNDLE_NAME" --directory bundle --no-same-owner --no-same-permissions
test -f bundle/r0-bundle-manifest.json
test -f bundle/deployment/r0-audit/r0-artisan.php
test -f bundle/deployment/r0-audit/r0-host-audit.php
test ! -e bundle/.env
R0_PHP="$(command -v php8.3 || command -v php8.2 || command -v php || true)"
test -n "$R0_PHP"
R0_VERSION_ID="$("$R0_PHP" -r 'echo PHP_VERSION_ID;')"
test "$R0_VERSION_ID" -ge 80200
test "$R0_VERSION_ID" -lt 90000
printf 'R0_STEP_4=PASS\n'
printf 'bundle_integrity=PASS\n'
printf 'php_cli_compatible=true\n'
'@
}

function Get-Step2ContentInspectionScript {
    return @'
set -eu
ACTUAL_HOME=$(cd -- ${HOME} && pwd -P)
case ${ACTUAL_HOME} in
  /home/[A-Za-z0-9._-]*) ;;
  *) exit 41 ;;
esac
AUDIT_ROOT=${ACTUAL_HOME}/.ir1-r0-audit
CANDIDATE_NAME=924af91188cc60d33ff87c91b94ecc1d539566e6
CANDIDATE_DIR=${AUDIT_ROOT}/${CANDIDATE_NAME}
BUNDLE_NAME=ir1-r0-audit-bundle-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz
BUNDLE_PATH=${CANDIDATE_DIR}/${BUNDLE_NAME}
BUNDLE_DIR=${CANDIDATE_DIR}/bundle
EXPECTED_BUNDLE_HASH=a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d
EXPECTED_MANIFEST_HASH=a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7
test -d ${AUDIT_ROOT}
test ! -L ${AUDIT_ROOT}
test -d ${CANDIDATE_DIR}
test ! -L ${CANDIDATE_DIR}
if test -w ${AUDIT_ROOT}; then ROOT_WRITABLE=yes; else ROOT_WRITABLE=no; fi
if test -w ${CANDIDATE_DIR}; then CANDIDATE_WRITABLE=yes; else CANDIDATE_WRITABLE=no; fi
ROOT_ENTRY_COUNT=$(find ${AUDIT_ROOT} -mindepth 1 -maxdepth 1 -print | wc -l | tr -d '[:space:]')
ROOT_UNEXPECTED_COUNT=$(find ${AUDIT_ROOT} -mindepth 1 -maxdepth 1 ! -name ${CANDIDATE_NAME} -print | wc -l | tr -d '[:space:]')
CANDIDATE_ENTRY_COUNT=$(find ${CANDIDATE_DIR} -mindepth 1 -maxdepth 1 -print | wc -l | tr -d '[:space:]')
CANDIDATE_UNEXPECTED_COUNT=$(find ${CANDIDATE_DIR} -mindepth 1 -maxdepth 1 ! -name ${BUNDLE_NAME} ! -name bundle -print | wc -l | tr -d '[:space:]')
SYMLINK_COUNT=$(find ${CANDIDATE_DIR} -type l -print | wc -l | tr -d '[:space:]')
if test -L ${BUNDLE_PATH}; then
  ARCHIVE_STATE=symlink
  ARCHIVE_INTEGRITY=not_applicable
elif test -f ${BUNDLE_PATH}; then
  ARCHIVE_STATE=regular_file
  ACTUAL_BUNDLE_HASH=$(sha256sum ${BUNDLE_PATH} | awk '{print $1}')
  if test ${ACTUAL_BUNDLE_HASH} = ${EXPECTED_BUNDLE_HASH}; then ARCHIVE_INTEGRITY=match; else ARCHIVE_INTEGRITY=mismatch; fi
elif test -e ${BUNDLE_PATH}; then
  ARCHIVE_STATE=other
  ARCHIVE_INTEGRITY=not_applicable
else
  ARCHIVE_STATE=absent
  ARCHIVE_INTEGRITY=not_applicable
fi
if test -L ${BUNDLE_DIR}; then
  BUNDLE_DIR_STATE=symlink
elif test -d ${BUNDLE_DIR}; then
  BUNDLE_DIR_STATE=directory
elif test -e ${BUNDLE_DIR}; then
  BUNDLE_DIR_STATE=other
else
  BUNDLE_DIR_STATE=absent
fi
if test ${BUNDLE_DIR_STATE} = directory; then
  MANIFEST_PATH=${BUNDLE_DIR}/r0-bundle-manifest.json
  if test -L ${MANIFEST_PATH}; then
    MANIFEST_INTEGRITY=symlink
  elif test -f ${MANIFEST_PATH}; then
    ACTUAL_MANIFEST_HASH=$(sha256sum ${MANIFEST_PATH} | awk '{print $1}')
    if test ${ACTUAL_MANIFEST_HASH} = ${EXPECTED_MANIFEST_HASH}; then MANIFEST_INTEGRITY=match; else MANIFEST_INTEGRITY=mismatch; fi
  else
    MANIFEST_INTEGRITY=missing
  fi
  if test -e ${BUNDLE_DIR}/.env || test -L ${BUNDLE_DIR}/.env; then BUNDLE_ENV_PRESENT=yes; else BUNDLE_ENV_PRESENT=no; fi
else
  MANIFEST_INTEGRITY=not_inspected
  BUNDLE_ENV_PRESENT=not_inspected
fi
printf 'R0_STEP_2_CONTENT_INSPECTION=PASS\n'
printf 'audit_root_writable=%s\n' ${ROOT_WRITABLE}
printf 'candidate_directory_writable=%s\n' ${CANDIDATE_WRITABLE}
printf 'audit_root_entry_count=%s\n' ${ROOT_ENTRY_COUNT}
printf 'audit_root_unexpected_entry_count=%s\n' ${ROOT_UNEXPECTED_COUNT}
printf 'candidate_entry_count=%s\n' ${CANDIDATE_ENTRY_COUNT}
printf 'candidate_unexpected_entry_count=%s\n' ${CANDIDATE_UNEXPECTED_COUNT}
printf 'candidate_symlink_count=%s\n' ${SYMLINK_COUNT}
printf 'bundle_archive_state=%s\n' ${ARCHIVE_STATE}
printf 'bundle_archive_integrity=%s\n' ${ARCHIVE_INTEGRITY}
printf 'bundle_directory_state=%s\n' ${BUNDLE_DIR_STATE}
printf 'bundle_manifest_integrity=%s\n' ${MANIFEST_INTEGRITY}
printf 'bundle_env_present=%s\n' ${BUNDLE_ENV_PRESENT}
printf 'production_change_scope=none_read_only_content_inspection\n'
'@
}

function Get-Step5Script {
    return @'
set -eu
ACTUAL_HOME="$(cd "$HOME" && pwd -P)"
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac
AUDIT_DIR="$ACTUAL_HOME/.ir1-r0-audit/924af91188cc60d33ff87c91b94ecc1d539566e6/bundle"
ENV_FILE="$ACTUAL_HOME/rise-gate.com/rise-gate-os/.env"
test -f "$ENV_FILE"
R0_PHP="$(command -v php8.3 || command -v php8.2 || command -v php || true)"
test -n "$R0_PHP"
cd "$AUDIT_DIR"
exec env LOG_CHANNEL=stderr IR1_R0_ENV_FILE="$ENV_FILE" "$R0_PHP" deployment/r0-audit/r0-artisan.php release:audit-r0 --confirm-read-only=IR1-R0-READ-ONLY --bundle-manifest=r0-bundle-manifest.json
'@
}

function Get-Step6Script {
    return @'
set -eu
ACTUAL_HOME="$(cd "$HOME" && pwd -P)"
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac
AUDIT_DIR="$ACTUAL_HOME/.ir1-r0-audit/924af91188cc60d33ff87c91b94ecc1d539566e6/bundle"
R0_PHP="$(command -v php8.3 || command -v php8.2 || command -v php || true)"
test -n "$R0_PHP"
cd "$AUDIT_DIR"
exec "$R0_PHP" deployment/r0-audit/r0-host-audit.php --bundle-manifest=r0-bundle-manifest.json --topology-profile=legacy-fixed-root --application-root="$ACTUAL_HOME/rise-gate.com/rise-gate-os" --public-root="$ACTUAL_HOME/rise-gate.com/public_html/os.rise-gate.com" --legacy-revision-marker="$ACTUAL_HOME/rise-gate.com/public_html/.rise-gate-deploy-revision" --legacy-staging-root="$ACTUAL_HOME/.rise-gate-os-deploy" --backup-root="$ACTUAL_HOME/rise-gate.com/public_html/_backup"
'@
}

function Assert-HelperContract {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $source = Get-Content -Raw -LiteralPath $PSCommandPath
    foreach ($required in @(
        'BatchMode=yes',
        'StrictHostKeyChecking=yes',
        'NumberOfPasswordPrompts=0',
        'ConnectionAttempts=1',
        'ClearAllForwardings=yes',
        'STEP_RETRY_FORBIDDEN',
        'production_change_scope=isolated_audit_directories_only'
    )) {
        if (-not $source.Contains($required)) {
            Stop-R0 'HELPER_CONTRACT_INCOMPLETE'
        }
    }
    $forbiddenOperations = @(
        ('Remove-Item ' + '-Recurse'),
        ('rm ' + '-rf'),
        ('git ' + 'push'),
        ('migrate ' + '--force'),
        ('config' + ':clear'),
        ('cache' + ':clear')
    )
    foreach ($forbidden in $forbiddenOperations) {
        if ($source.Contains($forbidden)) {
            Stop-R0 'HELPER_FORBIDDEN_OPERATION_PRESENT'
        }
    }

    $bundle = Join-Path $RepositoryRoot "storage\app\release-audit\ir1-r0-audit-bundle-$script:R0Candidate.tar.gz"
    if (-not (Test-Path -LiteralPath $bundle -PathType Leaf) -or
        (Get-FileHash -LiteralPath $bundle -Algorithm SHA256).Hash.ToLowerInvariant() -ne $script:R0BundleSha256) {
        Stop-R0 'HELPER_BUNDLE_BINDING_FAILED'
    }
}

try {
    $script:R0FailureStage = 'REPOSITORY_ROOT'
    $repositoryRoot = Get-RepositoryRoot
    $script:R0FailureStage = 'HELPER_INTEGRITY'
    $helperSha256 = Get-HelperSha256
    Assert-HelperContract -RepositoryRoot $repositoryRoot

    if ($VerifyOnly) {
        Invoke-HelperSelfTest
        Write-Output 'R0_HELPER_VERIFY=PASS'
        Write-Output 'retry_guard_verified=true'
        Write-Output 'sequence_guard_verified=true'
        Write-Output 'secret_output_guard_verified=true'
        Write-Output 'production_scope_guard_verified=true'
        Write-Output 'native_stderr_capture_verified=true'
        Write-Output "candidate=$script:R0Candidate"
        Write-Output "helper_sha256=$helperSha256"
        Write-Output 'network_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }

    $script:R0FailureStage = 'LOCAL_PRECONDITIONS'
    $preconditions = Get-LocalPreconditions -RepositoryRoot $repositoryRoot
    if ($VerifyLocalPreconditionsOnly) {
        Write-Output 'R0_LOCAL_PRECONDITIONS=PASS'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }
    $executionRoot = Join-Path $repositoryRoot "storage\app\release-audit\production-r0-human-$script:R0Candidate"
    $executionRoot = Join-Path $repositoryRoot (
        'storage\app\release-audit\production-r0-human-' + $script:R0Candidate + '-' + $script:R0ExecutionGeneration
    )
    $statePath = Join-Path $executionRoot 'execution-state.json'

    if ($InspectStep2RemoteStateOnly) {
        $script:R0FailureStage = 'STEP_2_STATE_INSPECTION_PRECONDITIONS'
        if ($Step -ne 2) {
            Stop-R0 'STEP_2_STATE_INSPECTION_STEP_MISMATCH'
        }
        $priorState = Read-R0State -Path $statePath
        $priorAttempts = @($priorState.attempts)
        if ($priorState.last_step -ne 2 -or
            $priorState.last_status -ne 'STOP' -or
            $priorAttempts.Count -ne 1 -or
            $priorAttempts[0].safe_error_code -ne 'STEP_2_REMOTE_PREPARATION_FAILED') {
            Stop-R0 'STEP_2_STATE_INSPECTION_PRIOR_STATE_MISMATCH'
        }

        $script:R0InspectionEvidencePath = Join-Path $executionRoot 'step2-remote-state-inspection.json'
        if (Test-Path -LiteralPath $script:R0InspectionEvidencePath) {
            Stop-R0 'STEP_2_STATE_INSPECTION_RETRY_FORBIDDEN'
        }
        $script:R0InspectionEvidence = [pscustomobject]@{
            schema_version = 1
            candidate = $script:R0Candidate
            execution_generation = $script:R0ExecutionGeneration
            inspection_id = 'step2-state-inspection-1'
            helper_sha256 = $helperSha256
            status = 'ATTEMPT_STARTED'
            started_at_jst = Get-JstTimestamp
            completed_at_jst = $null
            production_connection_attempted = $false
            production_change_scope = 'none_read_only_state_inspection'
            remote_exit_code = $null
            stderr_sha256 = $null
            stderr_bytes = 0
            safe_error_code = $null
            retry_performed = $false
            raw_stdout_stored = $false
            raw_stderr_stored = $false
            result = $null
        }
        Save-R0InspectionEvidence

        $script:R0FailureStage = 'STEP_2_REMOTE_STATE_INSPECTION'
        $script:R0ProductionConnectionAttempted = $true
        $script:R0InspectionEvidence.production_connection_attempted = $true
        Save-R0InspectionEvidence
        $inspectionResult = Invoke-RemoteScript `
            -SshPath $preconditions.SshPath `
            -Script (Get-Step2StateInspectionScript)
        $script:R0InspectionEvidence.remote_exit_code = $inspectionResult.ExitCode
        $script:R0InspectionEvidence.stderr_sha256 = if ([string]::IsNullOrEmpty($inspectionResult.Stderr)) {
            $null
        }
        else {
            Get-Sha256Text $inspectionResult.Stderr
        }
        $script:R0InspectionEvidence.stderr_bytes = if ([string]::IsNullOrEmpty($inspectionResult.Stderr)) {
            0
        }
        else {
            [Text.Encoding]::UTF8.GetByteCount($inspectionResult.Stderr)
        }
        if ($inspectionResult.ExitCode -ne 0) {
            Stop-R0 'STEP_2_REMOTE_STATE_INSPECTION_FAILED'
        }

        $expectedKeys = @(
            'R0_STEP_2_STATE_INSPECTION',
            'ssh_authentication',
            'home_identity',
            'legacy_application_root',
            'legacy_public_root',
            'audit_root',
            'candidate_directory',
            'production_change_scope'
        )
        $actualLines = @($inspectionResult.Stdout -split '\r?\n' | Where-Object { $_ -ne '' })
        if ($actualLines.Count -ne $expectedKeys.Count) {
            Stop-R0 'STEP_2_STATE_INSPECTION_OUTPUT_REJECTED'
        }
        $values = @{}
        for ($index = 0; $index -lt $expectedKeys.Count; $index++) {
            $parts = $actualLines[$index] -split '=', 2
            if ($parts.Count -ne 2 -or $parts[0] -ne $expectedKeys[$index]) {
                Stop-R0 'STEP_2_STATE_INSPECTION_OUTPUT_REJECTED'
            }
            $values[$parts[0]] = $parts[1]
        }
        if ($values.R0_STEP_2_STATE_INSPECTION -ne 'PASS' -or
            $values.ssh_authentication -ne 'established' -or
            $values.home_identity -ne 'pass' -or
            $values.legacy_application_root -notin @('present', 'missing') -or
            $values.legacy_public_root -notin @('present', 'missing') -or
            $values.audit_root -notin @('absent', 'directory', 'symlink', 'other') -or
            $values.candidate_directory -notin @('absent', 'directory', 'symlink', 'other', 'not_inspected') -or
            $values.production_change_scope -ne 'none_read_only_state_inspection' -or
            (($values.audit_root -eq 'directory') -ne ($values.candidate_directory -ne 'not_inspected'))) {
            Stop-R0 'STEP_2_STATE_INSPECTION_OUTPUT_REJECTED'
        }

        $script:R0InspectionEvidence.status = 'PASS'
        $script:R0InspectionEvidence.completed_at_jst = Get-JstTimestamp
        $script:R0InspectionEvidence.result = [pscustomobject]@{
            ssh_authentication = $values.ssh_authentication
            home_identity = $values.home_identity
            legacy_application_root = $values.legacy_application_root
            legacy_public_root = $values.legacy_public_root
            audit_root = $values.audit_root
            candidate_directory = $values.candidate_directory
        }
        Save-R0InspectionEvidence
        foreach ($key in $expectedKeys) {
            Write-Output ($key + '=' + $values[$key])
        }
        Write-Output 'retry_available=false'
        Write-Output 'secret_output=false'
        Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
        exit 0
    }

    if ($InspectStep2RemoteContentsOnly) {
        $script:R0FailureStage = 'STEP_2_CONTENT_INSPECTION_PRECONDITIONS'
        if ($Step -ne 2) {
            Stop-R0 'STEP_2_CONTENT_INSPECTION_STEP_MISMATCH'
        }
        $stateInspectionPath = Join-Path $executionRoot 'step2-remote-state-inspection.json'
        if (-not (Test-Path -LiteralPath $stateInspectionPath -PathType Leaf)) {
            Stop-R0 'STEP_2_CONTENT_INSPECTION_STATE_EVIDENCE_MISSING'
        }
        try {
            $stateInspection = Get-Content -Raw -LiteralPath $stateInspectionPath | ConvertFrom-Json
        }
        catch {
            Stop-R0 'STEP_2_CONTENT_INSPECTION_STATE_EVIDENCE_INVALID'
        }
        if ($stateInspection.status -ne 'PASS' -or
            $stateInspection.inspection_id -ne 'step2-state-inspection-1' -or
            $stateInspection.result.audit_root -ne 'directory' -or
            $stateInspection.result.candidate_directory -ne 'directory') {
            Stop-R0 'STEP_2_CONTENT_INSPECTION_STATE_EVIDENCE_MISMATCH'
        }

        $script:R0InspectionEvidencePath = Join-Path $executionRoot 'step2-remote-content-inspection.json'
        if (Test-Path -LiteralPath $script:R0InspectionEvidencePath) {
            Stop-R0 'STEP_2_CONTENT_INSPECTION_RETRY_FORBIDDEN'
        }
        $script:R0InspectionEvidence = [pscustomobject]@{
            schema_version = 1
            candidate = $script:R0Candidate
            execution_generation = $script:R0ExecutionGeneration
            inspection_id = 'step2-content-inspection-1'
            helper_sha256 = $helperSha256
            status = 'ATTEMPT_STARTED'
            started_at_jst = Get-JstTimestamp
            completed_at_jst = $null
            production_connection_attempted = $false
            production_change_scope = 'none_read_only_content_inspection'
            remote_exit_code = $null
            stderr_sha256 = $null
            stderr_bytes = 0
            safe_error_code = $null
            retry_performed = $false
            raw_stdout_stored = $false
            raw_stderr_stored = $false
            result = $null
        }
        Save-R0InspectionEvidence

        $script:R0FailureStage = 'STEP_2_REMOTE_CONTENT_INSPECTION'
        $script:R0ProductionConnectionAttempted = $true
        $script:R0InspectionEvidence.production_connection_attempted = $true
        Save-R0InspectionEvidence
        $contentResult = Invoke-RemoteScript `
            -SshPath $preconditions.SshPath `
            -Script (Get-Step2ContentInspectionScript)
        $script:R0InspectionEvidence.remote_exit_code = $contentResult.ExitCode
        $script:R0InspectionEvidence.stderr_sha256 = if ([string]::IsNullOrEmpty($contentResult.Stderr)) {
            $null
        }
        else {
            Get-Sha256Text $contentResult.Stderr
        }
        $script:R0InspectionEvidence.stderr_bytes = if ([string]::IsNullOrEmpty($contentResult.Stderr)) {
            0
        }
        else {
            [Text.Encoding]::UTF8.GetByteCount($contentResult.Stderr)
        }
        if ($contentResult.ExitCode -ne 0) {
            Stop-R0 'STEP_2_REMOTE_CONTENT_INSPECTION_FAILED'
        }

        $expectedKeys = @(
            'R0_STEP_2_CONTENT_INSPECTION',
            'audit_root_writable',
            'candidate_directory_writable',
            'audit_root_entry_count',
            'audit_root_unexpected_entry_count',
            'candidate_entry_count',
            'candidate_unexpected_entry_count',
            'candidate_symlink_count',
            'bundle_archive_state',
            'bundle_archive_integrity',
            'bundle_directory_state',
            'bundle_manifest_integrity',
            'bundle_env_present',
            'production_change_scope'
        )
        $actualLines = @($contentResult.Stdout -split '\r?\n' | Where-Object { $_ -ne '' })
        if ($actualLines.Count -ne $expectedKeys.Count) {
            Stop-R0 'STEP_2_CONTENT_INSPECTION_OUTPUT_REJECTED'
        }
        $values = @{}
        for ($index = 0; $index -lt $expectedKeys.Count; $index++) {
            $parts = $actualLines[$index] -split '=', 2
            if ($parts.Count -ne 2 -or $parts[0] -ne $expectedKeys[$index]) {
                Stop-R0 'STEP_2_CONTENT_INSPECTION_OUTPUT_REJECTED'
            }
            $values[$parts[0]] = $parts[1]
        }
        foreach ($numericKey in @(
            'audit_root_entry_count',
            'audit_root_unexpected_entry_count',
            'candidate_entry_count',
            'candidate_unexpected_entry_count',
            'candidate_symlink_count'
        )) {
            if ($values[$numericKey] -notmatch '^\d+$') {
                Stop-R0 'STEP_2_CONTENT_INSPECTION_OUTPUT_REJECTED'
            }
        }
        if ($values.R0_STEP_2_CONTENT_INSPECTION -ne 'PASS' -or
            $values.audit_root_writable -notin @('yes', 'no') -or
            $values.candidate_directory_writable -notin @('yes', 'no') -or
            $values.bundle_archive_state -notin @('absent', 'regular_file', 'symlink', 'other') -or
            $values.bundle_archive_integrity -notin @('match', 'mismatch', 'not_applicable') -or
            $values.bundle_directory_state -notin @('absent', 'directory', 'symlink', 'other') -or
            $values.bundle_manifest_integrity -notin @('match', 'mismatch', 'missing', 'symlink', 'not_inspected') -or
            $values.bundle_env_present -notin @('yes', 'no', 'not_inspected') -or
            $values.production_change_scope -ne 'none_read_only_content_inspection' -or
            (($values.bundle_archive_state -eq 'regular_file') -ne ($values.bundle_archive_integrity -in @('match', 'mismatch'))) -or
            (($values.bundle_directory_state -eq 'directory') -ne ($values.bundle_manifest_integrity -ne 'not_inspected')) -or
            (($values.bundle_directory_state -eq 'directory') -ne ($values.bundle_env_present -ne 'not_inspected'))) {
            Stop-R0 'STEP_2_CONTENT_INSPECTION_OUTPUT_REJECTED'
        }

        $script:R0InspectionEvidence.status = 'PASS'
        $script:R0InspectionEvidence.completed_at_jst = Get-JstTimestamp
        $script:R0InspectionEvidence.result = [pscustomobject]@{
            audit_root_writable = $values.audit_root_writable
            candidate_directory_writable = $values.candidate_directory_writable
            audit_root_entry_count = [int] $values.audit_root_entry_count
            audit_root_unexpected_entry_count = [int] $values.audit_root_unexpected_entry_count
            candidate_entry_count = [int] $values.candidate_entry_count
            candidate_unexpected_entry_count = [int] $values.candidate_unexpected_entry_count
            candidate_symlink_count = [int] $values.candidate_symlink_count
            bundle_archive_state = $values.bundle_archive_state
            bundle_archive_integrity = $values.bundle_archive_integrity
            bundle_directory_state = $values.bundle_directory_state
            bundle_manifest_integrity = $values.bundle_manifest_integrity
            bundle_env_present = $values.bundle_env_present
        }
        Save-R0InspectionEvidence
        foreach ($key in $expectedKeys) {
            Write-Output ($key + '=' + $values[$key])
        }
        Write-Output 'retry_available=false'
        Write-Output 'secret_output=false'
        Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
        exit 0
    }

    $script:R0FailureStage = 'ATTEMPT_STATE'
    Start-R0Attempt -ExecutionRoot $executionRoot -StatePath $statePath -HelperSha256 $helperSha256

    switch ($Step) {
        2 {
            $script:R0FailureStage = 'STEP_2_REMOTE_PREPARATION'
            $script:R0ProductionConnectionAttempted = $true
            $result = Invoke-RemoteScript -SshPath $preconditions.SshPath -Script (Get-Step2Script)
            if ($result.ExitCode -ne 0) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_2_REMOTE_PREPARATION_FAILED' -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
                Stop-R0 'STEP_2_REMOTE_PREPARATION_FAILED'
            }
            Assert-FixedSuccessOutput -Actual $result.Stdout -ExpectedLines @(
                'R0_STEP_2=PASS',
                'production_change_scope=isolated_audit_directories_only'
            )
            Complete-R0Attempt -Status PASS -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
            Write-Output 'R0_STEP_2=PASS'
            Write-Output 'production_change_scope=isolated_audit_directories_only'
        }
        3 {
            $script:R0FailureStage = 'STEP_3_REMOTE_PRECHECK'
            $script:R0ProductionConnectionAttempted = $true
            $precheck = Invoke-RemoteScript -SshPath $preconditions.SshPath -Script (Get-Step3PrecheckScript)
            if ($precheck.ExitCode -ne 0) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_3_REMOTE_PRECONDITION_FAILED' -RemoteExitCode $precheck.ExitCode -Stderr $precheck.Stderr
                Stop-R0 'STEP_3_REMOTE_PRECONDITION_FAILED'
            }
            Assert-FixedSuccessOutput -Actual $precheck.Stdout -ExpectedLines @('R0_STEP_3_PRECHECK=PASS')
            $script:R0FailureStage = 'STEP_3_BUNDLE_UPLOAD'
            $remoteDestination = "$script:R0SshAlias`:.ir1-r0-audit/$script:R0Candidate/$($preconditions.BundleName)"
            $scpArguments = @(Get-CommonSshArguments) + @($preconditions.BundlePath, $remoteDestination)
            $upload = Invoke-CapturedProcess -FilePath $preconditions.ScpPath -Arguments $scpArguments -StandardInput $null
            if ($upload.ExitCode -ne 0) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_3_BUNDLE_UPLOAD_FAILED' -RemoteExitCode $upload.ExitCode -Stderr $upload.Stderr
                Stop-R0 'STEP_3_BUNDLE_UPLOAD_FAILED'
            }
            Complete-R0Attempt -Status PASS -RemoteExitCode $upload.ExitCode -Stderr $upload.Stderr
            Write-Output 'R0_STEP_3=PASS'
            Write-Output 'production_change_scope=single_audit_archive_upload_only'
        }
        4 {
            $script:R0FailureStage = 'STEP_4_VERIFY_AND_EXTRACT'
            $script:R0ProductionConnectionAttempted = $true
            $result = Invoke-RemoteScript -SshPath $preconditions.SshPath -Script (Get-Step4Script)
            if ($result.ExitCode -ne 0) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_4_VERIFY_OR_EXTRACT_FAILED' -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
                Stop-R0 'STEP_4_VERIFY_OR_EXTRACT_FAILED'
            }
            Assert-FixedSuccessOutput -Actual $result.Stdout -ExpectedLines @(
                'R0_STEP_4=PASS',
                'bundle_integrity=PASS',
                'php_cli_compatible=true'
            )
            Complete-R0Attempt -Status PASS -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
            Write-Output 'R0_STEP_4=PASS'
            Write-Output 'production_change_scope=isolated_bundle_extraction_only'
        }
        5 {
            $script:R0FailureStage = 'STEP_5_APPLICATION_DB_AUDIT'
            $script:R0ProductionConnectionAttempted = $true
            $result = Invoke-RemoteScript -SshPath $preconditions.SshPath -Script (Get-Step5Script)
            if (-not (Test-SafeJsonEvidence -Value $result.Stdout)) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_5_EVIDENCE_OUTPUT_REJECTED' -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
                Stop-R0 'STEP_5_EVIDENCE_OUTPUT_REJECTED'
            }
            $evidencePath = Join-Path $executionRoot 'application-db.stdout.json'
            [IO.File]::WriteAllText($evidencePath, $result.Stdout, [Text.UTF8Encoding]::new($false))
            if ($result.ExitCode -ne 0) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_5_APPLICATION_AUDIT_NONZERO' -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
                Stop-R0 'STEP_5_APPLICATION_AUDIT_NONZERO'
            }
            Complete-R0Attempt -Status PASS -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
            Write-Output 'R0_STEP_5=PASS'
            Write-Output 'production_change_scope=none_read_only_application_db_audit'
        }
        6 {
            $script:R0FailureStage = 'STEP_6_HOST_AUDIT'
            $script:R0ProductionConnectionAttempted = $true
            $result = Invoke-RemoteScript -SshPath $preconditions.SshPath -Script (Get-Step6Script)
            if (-not (Test-SafeJsonEvidence -Value $result.Stdout)) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_6_EVIDENCE_OUTPUT_REJECTED' -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
                Stop-R0 'STEP_6_EVIDENCE_OUTPUT_REJECTED'
            }
            $evidencePath = Join-Path $executionRoot 'host.stdout.json'
            [IO.File]::WriteAllText($evidencePath, $result.Stdout, [Text.UTF8Encoding]::new($false))
            if ($result.ExitCode -ne 0) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_6_HOST_AUDIT_NONZERO' -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
                Stop-R0 'STEP_6_HOST_AUDIT_NONZERO'
            }
            Complete-R0Attempt -Status PASS -RemoteExitCode $result.ExitCode -Stderr $result.Stderr
            Write-Output 'R0_STEP_6=PASS'
            Write-Output 'production_change_scope=none_read_only_host_audit'
        }
    }

    Write-Output 'retry_available=false'
    Write-Output 'secret_output=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 0
}
catch {
    $caughtException = $_.Exception
    $safeErrorCode = if ($caughtException.Message -match '^[A-Z0-9_]+$') {
        $caughtException.Message
    }
    elseif ($caughtException -is [System.Management.Automation.RemoteException] -and
        $script:R0FailureStage.StartsWith('LOCAL_')) {
        'LOCAL_NATIVE_PROCESS_CAPTURE_FAILURE'
    }
    else {
        'UNEXPECTED_LOCAL_FAILURE'
    }

    if ($null -ne $script:R0InspectionEvidence -and
        $script:R0InspectionEvidence.status -eq 'ATTEMPT_STARTED') {
        try {
            $script:R0InspectionEvidence.status = 'STOP'
            $script:R0InspectionEvidence.completed_at_jst = Get-JstTimestamp
            $script:R0InspectionEvidence.safe_error_code = $safeErrorCode
            Save-R0InspectionEvidence
        }
        catch {
            $safeErrorCode = 'LOCAL_INSPECTION_STATE_FINALIZATION_FAILED'
        }
    }

    if ($null -ne $script:R0State -and
        [string] $script:R0State.last_status -eq 'ATTEMPT_STARTED') {
        try {
            Complete-R0Attempt -Status STOP -SafeErrorCode $safeErrorCode -Stderr ''
        }
        catch {
            $safeErrorCode = 'LOCAL_STATE_FINALIZATION_FAILED'
        }
    }

    if (-not $VerifyOnly) {
        try {
            $receiptRepositoryRoot = Get-RepositoryRoot
            Write-SanitizedFailureReceipt `
                -RepositoryRoot $receiptRepositoryRoot `
                -SafeErrorCode $safeErrorCode `
                -ExceptionType $caughtException.GetType().FullName `
                -ExceptionMessage $caughtException.Message
        }
        catch {
            $safeErrorCode = 'FAILURE_RECEIPT_WRITE_FAILED'
        }
    }

    Write-R0Stop -SafeErrorCode $safeErrorCode
    exit 1
}
