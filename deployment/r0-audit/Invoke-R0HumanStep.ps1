[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet(2, 3, 4, 5, 6)]
    [int] $Step,

    [switch] $VerifyOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:R0Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$script:R0BundleId = '5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0'
$script:R0BundleSha256 = 'a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d'
$script:R0SshAlias = 'company-os-production'
$script:R0IdentityFile = 'codex-company-os-production'
$script:R0ProtocolVersion = 1
$script:R0CurrentStep = $Step
$script:R0State = $null
$script:R0StatePath = $null

function Write-R0Stop {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)

    Write-Output "R0_STEP_$script:R0CurrentStep=STOP"
    Write-Output "safe_error_code=$SafeErrorCode"
    Write-Output 'retry_performed=false'
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

function Save-R0State {
    if ($null -eq $script:R0State -or [string]::IsNullOrWhiteSpace($script:R0StatePath)) {
        Stop-R0 'LOCAL_STATE_NOT_INITIALIZED'
    }

    $json = $script:R0State | ConvertTo-Json -Depth 8
    $temporaryPath = "$script:R0StatePath.tmp"
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $script:R0StatePath -Force
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
    try {
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
        Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}

function Get-LocalPreconditions {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $bundleName = "ir1-r0-audit-bundle-$script:R0Candidate.tar.gz"
    $bundlePath = Join-Path $RepositoryRoot "storage\app\release-audit\$bundleName"
    if (-not (Test-Path -LiteralPath $bundlePath -PathType Leaf)) {
        Stop-R0 'AUDIT_BUNDLE_MISSING'
    }
    if ((Get-FileHash -LiteralPath $bundlePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $script:R0BundleSha256) {
        Stop-R0 'AUDIT_BUNDLE_HASH_MISMATCH'
    }

    $ssh = Get-Command 'ssh.exe' -ErrorAction SilentlyContinue
    $scp = Get-Command 'scp.exe' -ErrorAction SilentlyContinue
    $sshKeygen = Get-Command 'ssh-keygen.exe' -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $scp -or $null -eq $sshKeygen) {
        Stop-R0 'OPENSSH_CLIENT_UNAVAILABLE'
    }

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

    $knownHosts = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHosts -PathType Leaf)) {
        Stop-R0 'KNOWN_HOSTS_MISSING'
    }
    $lookup = "[$($config.hostname)]:$($config.port)"
    $knownResult = Invoke-CapturedProcess -FilePath $sshKeygen.Source -Arguments @('-F', $lookup, '-f', $knownHosts) -StandardInput $null
    if ($knownResult.ExitCode -ne 0) {
        Stop-R0 'HOST_KEY_NOT_REGISTERED'
    }
    $knownTypes = @($knownResult.Stdout -split "`r?`n" |
        Where-Object { $_ -and -not $_.StartsWith('#') } |
        ForEach-Object { ($_ -split '\s+')[1] } |
        Sort-Object -Unique)
    if (($knownTypes -join ',') -ne 'ecdsa-sha2-nistp256,ssh-ed25519,ssh-rsa') {
        Stop-R0 'HOST_KEY_CONTRACT_MISMATCH'
    }

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
    $repositoryRoot = Get-RepositoryRoot
    $helperSha256 = Get-HelperSha256
    Assert-HelperContract -RepositoryRoot $repositoryRoot

    if ($VerifyOnly) {
        Invoke-HelperSelfTest
        Write-Output 'R0_HELPER_VERIFY=PASS'
        Write-Output 'retry_guard_verified=true'
        Write-Output 'sequence_guard_verified=true'
        Write-Output 'secret_output_guard_verified=true'
        Write-Output 'production_scope_guard_verified=true'
        Write-Output "candidate=$script:R0Candidate"
        Write-Output "helper_sha256=$helperSha256"
        Write-Output 'network_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }

    $preconditions = Get-LocalPreconditions -RepositoryRoot $repositoryRoot
    $executionRoot = Join-Path $repositoryRoot "storage\app\release-audit\production-r0-human-$script:R0Candidate"
    $statePath = Join-Path $executionRoot 'execution-state.json'
    Start-R0Attempt -ExecutionRoot $executionRoot -StatePath $statePath -HelperSha256 $helperSha256

    switch ($Step) {
        2 {
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
            $precheck = Invoke-RemoteScript -SshPath $preconditions.SshPath -Script (Get-Step3PrecheckScript)
            if ($precheck.ExitCode -ne 0) {
                Complete-R0Attempt -Status STOP -SafeErrorCode 'STEP_3_REMOTE_PRECONDITION_FAILED' -RemoteExitCode $precheck.ExitCode -Stderr $precheck.Stderr
                Stop-R0 'STEP_3_REMOTE_PRECONDITION_FAILED'
            }
            Assert-FixedSuccessOutput -Actual $precheck.Stdout -ExpectedLines @('R0_STEP_3_PRECHECK=PASS')
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
    $safeErrorCode = if ($_.Exception.Message -match '^[A-Z0-9_]+$') {
        $_.Exception.Message
    }
    else {
        'UNEXPECTED_LOCAL_FAILURE'
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

    Write-R0Stop -SafeErrorCode $safeErrorCode
    exit 1
}
