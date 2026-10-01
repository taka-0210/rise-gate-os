[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [switch] $VerifyLocalPreconditionsOnly,
    [switch] $Corrective1,
    [switch] $Corrective2
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$script:BundleId = '5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0'
$script:BundleSha256 = 'a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d'
$script:ManifestSha256 = 'a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7'
$script:R0StateSha256 = '159392dde20356febf20ea744953c7b901696e5c0f7dc5f45d1e2ae914f13b18'
$script:R0ApplicationEvidenceSha256 = '4cb2f91d7d12e1083e8edaee41a3a408d0b2577d46fa1c69ad7a978a83c84be9'
$script:R0HostEvidenceSha256 = '03f0a69dbeac35747082f06c34e2b492f03cd0bcc8ebd18dc251c81372d0a7ac'
$script:G1StateSha256 = 'ffd48f4e508b299e24921fb4a016b12f97950043af63abc80d471080ac4bf038'
$script:G1EvidenceSha256 = '15e20d272f39d1bd69290af6b5c2f681b52bb42a09273b783ca77ad7eacf4340'
$script:PreflightPhpSha256 = '76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c'
$script:OriginalHelperSha256 = '0e31b3e2d00eadf14ca7e278ea7ede21a91bed46c365257185634f8341fd51b3'
$script:OriginalPreflightPhpSha256 = '878a0399c50b0841e1d9c45cfb6b0f2cae1a297adf3d3bb9046ce7ebc7027cca'
$script:OriginalAttemptStateSha256 = '918cec809034fc752906ed9e140da10c2255e36009e76765b624d6d2c8cd8808'
$script:Corrective1HelperSha256 = '59de505cc3fe9af0ad4922ac20fe12a58c07d1cf0362e6683ea2144b66b3fd64'
$script:Corrective1PreflightPhpSha256 = '76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c'
$script:Corrective1AttemptStateSha256 = '81795b54e7419f8fb29e0d5de306d6768b76ab27da69badab7d6fcf3f2c69a55'
$script:SshAlias = 'company-os-production'
$script:IdentityFile = 'codex-company-os-production'
$script:FailureStage = 'BOOTSTRAP'
$script:ProductionConnectionAttempted = $false
$script:AttemptStarted = $false
$script:State = $null
$script:StatePath = $null

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
    return Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
}

function Stop-G2 {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)
    throw [System.InvalidOperationException]::new($SafeErrorCode)
}

function Write-G2Stop {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)

    Write-Output 'G2_MIGRATION_SAFETY_PREFLIGHT=STOP'
    Write-Output ('safe_error_code=' + $SafeErrorCode)
    Write-Output ('failure_stage=' + $script:FailureStage)
    Write-Output ('production_connection_attempted=' + $script:ProductionConnectionAttempted.ToString().ToLowerInvariant())
    Write-Output 'retry_performed=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
}

function Write-JsonAtomically {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)] $Value
    )

    $json = $Value | ConvertTo-Json -Depth 12
    $temporaryPath = $Path + '.tmp'
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $Path -Force
}

function Save-G2State {
    if ($null -eq $script:State -or [string]::IsNullOrWhiteSpace($script:StatePath)) {
        Stop-G2 'G2_STATE_NOT_INITIALIZED'
    }
    Write-JsonAtomically -Path $script:StatePath -Value $script:State
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
        $ErrorActionPreference = 'Continue'
        if ($null -eq $StandardInput) {
            & $FilePath @Arguments 1> $stdoutPath 2> $stderrPath
        }
        else {
            $StandardInput | & $FilePath @Arguments 1> $stdoutPath 2> $stderrPath
        }
        return [pscustomobject]@{
            ExitCode = $LASTEXITCODE
            Stdout = [IO.File]::ReadAllText($stdoutPath)
            Stderr = [IO.File]::ReadAllText($stderrPath)
        }
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
        Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}

function Assert-EvidenceFile {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $Sha256,
        [Parameter(Mandatory = $true)][string] $MissingCode,
        [Parameter(Mandatory = $true)][string] $MismatchCode
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Stop-G2 $MissingCode }
    if ((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Sha256) {
        Stop-G2 $MismatchCode
    }
}

function Assert-LocalEvidenceContract {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $r0Root = Join-Path $RepositoryRoot ('storage\app\release-audit\production-r0-human-' + $script:Candidate + '-corrective-2')
    Assert-EvidenceFile (Join-Path $r0Root 'execution-state.json') $script:R0StateSha256 'R0_EVIDENCE_MISSING' 'R0_EVIDENCE_HASH_MISMATCH'
    Assert-EvidenceFile (Join-Path $r0Root 'application-db.stdout.json') $script:R0ApplicationEvidenceSha256 'R0_EVIDENCE_MISSING' 'R0_EVIDENCE_HASH_MISMATCH'
    Assert-EvidenceFile (Join-Path $r0Root 'host.stdout.json') $script:R0HostEvidenceSha256 'R0_EVIDENCE_MISSING' 'R0_EVIDENCE_HASH_MISMATCH'

    $g1Root = Join-Path $RepositoryRoot ('storage\app\release-audit\production-g1-gap-closure-' + $script:Candidate)
    $g1StatePath = Join-Path $g1Root 'execution-state.json'
    $g1EvidencePath = Join-Path $g1Root 'g1-evidence.json'
    Assert-EvidenceFile $g1StatePath $script:G1StateSha256 'G1_EVIDENCE_MISSING' 'G1_EVIDENCE_HASH_MISMATCH'
    Assert-EvidenceFile $g1EvidencePath $script:G1EvidenceSha256 'G1_EVIDENCE_MISSING' 'G1_EVIDENCE_HASH_MISMATCH'

    try {
        $g1State = Get-Content -Raw -LiteralPath $g1StatePath | ConvertFrom-Json
        $g1Evidence = Get-Content -Raw -LiteralPath $g1EvidencePath | ConvertFrom-Json
    }
    catch { Stop-G2 'G1_EVIDENCE_INVALID' }

    if ($g1State.status -ne 'PASS' -or
        $g1State.candidate -ne $script:Candidate -or
        $g1State.production_mutation -ne $false -or
        $g1State.retry_performed -ne $false -or
        $g1Evidence.status -ne 'PASS' -or
        $g1Evidence.production_change_scope -ne 'none_read_only_g1_inspection' -or
        $g1Evidence.secret_output -ne $false) {
        Stop-G2 'G1_COMPLETION_CONTRACT_MISMATCH'
    }
}

function Assert-OriginalAttemptForCorrective {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $originalRoot = Join-Path $RepositoryRoot ('storage\app\release-audit\production-g2-migration-preflight-' + $script:Candidate)
    $originalStatePath = Join-Path $originalRoot 'execution-state.json'
    Assert-EvidenceFile $originalStatePath $script:OriginalAttemptStateSha256 'G2_ORIGINAL_ATTEMPT_MISSING' 'G2_ORIGINAL_ATTEMPT_HASH_MISMATCH'
    try { $original = Get-Content -Raw -LiteralPath $originalStatePath | ConvertFrom-Json }
    catch { Stop-G2 'G2_ORIGINAL_ATTEMPT_INVALID' }

    if ($original.status -ne 'STOP' -or
        $original.candidate -ne $script:Candidate -or
        $original.helper_sha256 -ne $script:OriginalHelperSha256 -or
        $original.preflight_php_sha256 -ne $script:OriginalPreflightPhpSha256 -or
        $original.safe_error_code -ne 'G2_REMOTE_PREFLIGHT_FAILED' -or
        $original.failure_stage -ne 'PRODUCTION_READ_ONLY_G2_PREFLIGHT' -or
        $original.production_connection_attempted -ne $true -or
        $original.production_mutation -ne $false -or
        $original.retry_performed -ne $false -or
        $original.remote_exit_code -ne 127) {
        Stop-G2 'G2_ORIGINAL_ATTEMPT_CONTRACT_MISMATCH'
    }
}

function Assert-Corrective1AttemptForCorrective2 {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $corrective1Root = Join-Path $RepositoryRoot ('storage\app\release-audit\production-g2-migration-preflight-corrective-1-' + $script:Candidate)
    $corrective1StatePath = Join-Path $corrective1Root 'execution-state.json'
    Assert-EvidenceFile $corrective1StatePath $script:Corrective1AttemptStateSha256 'G2_CORRECTIVE1_ATTEMPT_MISSING' 'G2_CORRECTIVE1_ATTEMPT_HASH_MISMATCH'
    try { $corrective1 = Get-Content -Raw -LiteralPath $corrective1StatePath | ConvertFrom-Json }
    catch { Stop-G2 'G2_CORRECTIVE1_ATTEMPT_INVALID' }

    if ($corrective1.execution_generation -ne 'corrective-1' -or
        $corrective1.status -ne 'STOP' -or
        $corrective1.candidate -ne $script:Candidate -or
        $corrective1.helper_sha256 -ne $script:Corrective1HelperSha256 -or
        $corrective1.preflight_php_sha256 -ne $script:Corrective1PreflightPhpSha256 -or
        $corrective1.safe_error_code -ne 'G2_REMOTE_PREFLIGHT_FAILED' -or
        $corrective1.failure_stage -ne 'PRODUCTION_READ_ONLY_G2_PREFLIGHT' -or
        $corrective1.production_connection_attempted -ne $true -or
        $corrective1.production_mutation -ne $false -or
        $corrective1.retry_performed -ne $false -or
        $corrective1.remote_exit_code -ne 1 -or
        $corrective1.stderr_bytes -ne 808 -or
        $null -ne $corrective1.remote_safe_error_code) {
        Stop-G2 'G2_CORRECTIVE1_ATTEMPT_CONTRACT_MISMATCH'
    }
}

function Get-LocalPreconditions {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $script:FailureStage = 'LOCAL_EVIDENCE_BINDING'
    Assert-LocalEvidenceContract -RepositoryRoot $RepositoryRoot
    if ($Corrective1 -and $Corrective2) {
        Stop-G2 'G2_CORRECTIVE_GENERATION_AMBIGUOUS'
    }
    if ($Corrective1) {
        Assert-OriginalAttemptForCorrective -RepositoryRoot $RepositoryRoot
    }
    if ($Corrective2) {
        Assert-OriginalAttemptForCorrective -RepositoryRoot $RepositoryRoot
        Assert-Corrective1AttemptForCorrective2 -RepositoryRoot $RepositoryRoot
    }

    $phpScript = Join-Path $RepositoryRoot 'deployment\r0-audit\g2-migration-preflight.php'
    Assert-EvidenceFile $phpScript $script:PreflightPhpSha256 'G2_PREFLIGHT_SCRIPT_MISSING' 'G2_PREFLIGHT_SCRIPT_HASH_MISMATCH'

    $script:FailureStage = 'LOCAL_OPENSSH_TOOL_DISCOVERY'
    $ssh = Get-Command 'ssh.exe' -ErrorAction SilentlyContinue
    $sshKeygen = Get-Command 'ssh-keygen.exe' -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $sshKeygen) { Stop-G2 'OPENSSH_CLIENT_UNAVAILABLE' }

    $script:FailureStage = 'LOCAL_SSH_CONFIG_PARSE'
    $configResult = Invoke-CapturedProcess -FilePath $ssh.Source -Arguments @('-G', $script:SshAlias) -StandardInput $null
    if ($configResult.ExitCode -ne 0) { Stop-G2 'SSH_CONFIG_UNAVAILABLE' }
    $config = @{}
    foreach ($line in ($configResult.Stdout -split "`r?`n")) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) { $config[$parts[0].ToLowerInvariant()] = $parts[1] }
    }
    foreach ($required in @('hostname','user','port','identityfile')) {
        if (-not $config.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($config[$required])) {
            Stop-G2 'SSH_CONFIG_INCOMPLETE'
        }
    }
    if ((Split-Path -Leaf $config.identityfile.Trim('"')) -ne $script:IdentityFile -or $config.identitiesonly -ne 'yes') {
        Stop-G2 'SSH_IDENTITY_CONTRACT_MISMATCH'
    }
    foreach ($directive in @('remotecommand','localcommand','proxycommand','proxyjump')) {
        if ($config.ContainsKey($directive) -and $config[$directive] -ne 'none') {
            Stop-G2 'SSH_AUTOMATIC_COMMAND_FORBIDDEN'
        }
    }

    $script:FailureStage = 'LOCAL_HOST_KEY_LOOKUP'
    $knownHosts = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHosts -PathType Leaf)) { Stop-G2 'KNOWN_HOSTS_MISSING' }
    $lookup = '[' + $config.hostname + ']:' + $config.port
    $knownResult = Invoke-CapturedProcess -FilePath $sshKeygen.Source -Arguments @('-F',$lookup,'-f',$knownHosts) -StandardInput $null
    if ($knownResult.ExitCode -ne 0) { Stop-G2 'HOST_KEY_NOT_REGISTERED' }
    $hostKeyTypes = @($knownResult.Stdout -split "`r?`n" | Where-Object { $_ -match '^\S+\s+(ssh-rsa|ecdsa-sha2-nistp256|ssh-ed25519)\s+' } | ForEach-Object { ($_ -split '\s+')[1] } | Sort-Object -Unique)
    if ($hostKeyTypes.Count -ne 3) { Stop-G2 'HOST_KEY_SET_INCOMPLETE' }

    return [pscustomobject]@{
        SshPath = $ssh.Source
        KnownHostsPath = $knownHosts
        PhpScriptPath = $phpScript
    }
}

function Get-RemoteScript {
    param([Parameter(Mandatory = $true)][string] $PhpSource)

    $delimiter = '__G2_PHP_SOURCE_924AF911__'
    if ($PhpSource -match ('(?m)^' + [regex]::Escape($delimiter) + '$')) {
        Stop-G2 'G2_PHP_HEREDOC_DELIMITER_COLLISION'
    }
    $prefix = @'
set -eu
g2_shell_stop() {
    printf '{"output_schema_version":1,"status":"INCONCLUSIVE","audit_mode":"read-only","evidence_completeness":"incomplete","failure":{"safe_error_code":"%s","failure_stage":"remote_shell_preflight"},"evidence":{"candidate":"924af91188cc60d33ff87c91b94ecc1d539566e6","partial_evidence":{"application_bootstrap":"not_used","database_connection":"not_attempted","last_completed_condition":"none","completed_conditions":[]},"sql_safety":{"allowed_statement_classes":["SELECT"],"statement_counts":{"SELECT":0},"total_statements":0,"rejected_statements":0,"statement_limit":24,"persistent_db_write":false,"ddl":false,"migration_execution":false}},"secret_output":false,"raw_identifier_output":false,"raw_exception_output":false,"production_change_scope":"none_read_only_g2_migration_preflight"}\n' "$1"
    exit 1
}
command -v sha256sum >/dev/null 2>&1 || g2_shell_stop G2_SHA256SUM_UNAVAILABLE
command -v find >/dev/null 2>&1 || g2_shell_stop G2_FIND_UNAVAILABLE
command -v wc >/dev/null 2>&1 || g2_shell_stop G2_WC_UNAVAILABLE
command -v env >/dev/null 2>&1 || g2_shell_stop G2_ENV_COMMAND_UNAVAILABLE
ACTUAL_HOME="$(cd "$HOME" && pwd -P)" || g2_shell_stop G2_HOME_RESOLUTION_FAILED
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) g2_shell_stop G2_HOME_IDENTITY_REJECTED ;; esac
CANDIDATE_DIR="$ACTUAL_HOME/.ir1-r0-audit/924af91188cc60d33ff87c91b94ecc1d539566e6"
AUDIT_DIR="$CANDIDATE_DIR/bundle"
ARCHIVE_PATH="$CANDIDATE_DIR/ir1-r0-audit-bundle-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz"
ENV_FILE="$ACTUAL_HOME/rise-gate.com/rise-gate-os/.env"
test -d "$CANDIDATE_DIR" && test ! -L "$CANDIDATE_DIR" || g2_shell_stop G2_CANDIDATE_DIRECTORY_REJECTED
test -f "$ARCHIVE_PATH" && test ! -L "$ARCHIVE_PATH" || g2_shell_stop G2_ARCHIVE_REJECTED
printf '%s  %s\n' a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d "$ARCHIVE_PATH" | sha256sum --check --strict - >/dev/null || g2_shell_stop G2_ARCHIVE_HASH_MISMATCH
test -d "$AUDIT_DIR" && test ! -L "$AUDIT_DIR" || g2_shell_stop G2_AUDIT_DIRECTORY_REJECTED
test "$(find "$AUDIT_DIR" -type l -print | wc -l)" -eq 0 || g2_shell_stop G2_AUDIT_SYMLINK_REJECTED
test -f "$AUDIT_DIR/r0-bundle-manifest.json" && test ! -L "$AUDIT_DIR/r0-bundle-manifest.json" || g2_shell_stop G2_MANIFEST_REJECTED
printf '%s  %s\n' a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7 "$AUDIT_DIR/r0-bundle-manifest.json" | sha256sum --check --strict - >/dev/null || g2_shell_stop G2_MANIFEST_HASH_MISMATCH
test ! -e "$AUDIT_DIR/.env" || g2_shell_stop G2_BUNDLE_ENV_REJECTED
test -f "$ENV_FILE" && test ! -L "$ENV_FILE" || g2_shell_stop G2_PRODUCTION_ENV_REJECTED
G2_PHP="$(command -v php8.3 || command -v php8.2 || command -v php || true)"
test -n "$G2_PHP" || g2_shell_stop G2_PHP_UNAVAILABLE
cd "$AUDIT_DIR" || g2_shell_stop G2_AUDIT_DIRECTORY_UNAVAILABLE
env LOG_CHANNEL=stderr G2_CANDIDATE=924af91188cc60d33ff87c91b94ecc1d539566e6 IR1_R0_ENV_FILE="$ENV_FILE" "$G2_PHP" <<'__G2_PHP_SOURCE_924AF911__'
'@
    return $prefix + $PhpSource + [Environment]::NewLine + $delimiter + [Environment]::NewLine
}

function Test-SafePassEvidence {
    param([Parameter(Mandatory = $true)][string] $Json)

    try { $evidence = $Json | ConvertFrom-Json }
    catch { return $false }

    if ($evidence.output_schema_version -ne 1 -or
        $evidence.status -ne 'PASS' -or
        $evidence.audit_mode -ne 'read-only' -or
        $evidence.evidence_completeness -ne 'complete_for_supported_g2_preflight_scope' -or
        $null -ne $evidence.failure -or
        $evidence.evidence.candidate -ne $script:Candidate -or
        $evidence.secret_output -ne $false -or
        $evidence.raw_identifier_output -ne $false -or
        $evidence.raw_exception_output -ne $false -or
        $evidence.production_change_scope -ne 'none_read_only_g2_migration_preflight') {
        return $false
    }
    $sql = $evidence.evidence.sql_safety
    if ($sql.result -ne 'PASS' -or
        $sql.allowed_statement_classes.Count -ne 1 -or
        $sql.allowed_statement_classes[0] -ne 'SELECT' -or
        $sql.total_statements -gt 24 -or
        $sql.total_statements -ne $sql.statement_counts.SELECT -or
        $sql.rejected_statements -ne 0 -or
        $sql.persistent_db_write -ne $false -or
        $sql.ddl -ne $false -or
        $sql.migration_execution -ne $false) {
        return $false
    }
    if ($Json -match '(?i)DB_PASSWORD|DB_USERNAME|DB_HOST|APP_KEY|AUTHORIZATION|PRIVATE KEY|BEGIN RSA|BEGIN OPENSSH|exception_message|raw_path|organization_id|user_id') {
        return $false
    }
    return $true
}

function Test-SafeFailureEvidence {
    param([Parameter(Mandatory = $true)][string] $Json)

    try { $evidence = $Json | ConvertFrom-Json }
    catch { return $false }

    if ($evidence.output_schema_version -ne 1 -or
        $evidence.status -ne 'INCONCLUSIVE' -or
        $evidence.audit_mode -ne 'read-only' -or
        $evidence.evidence_completeness -ne 'incomplete' -or
        $evidence.failure.safe_error_code -notmatch '^G2_[A-Z0-9_]+$' -or
        $evidence.failure.failure_stage -notmatch '^[a-z0-9_]+$' -or
        $evidence.evidence.candidate -ne $script:Candidate -or
        $evidence.secret_output -ne $false -or
        $evidence.raw_identifier_output -ne $false -or
        $evidence.raw_exception_output -ne $false -or
        $evidence.production_change_scope -ne 'none_read_only_g2_migration_preflight') {
        return $false
    }
    $sql = $evidence.evidence.sql_safety
    if ($sql.allowed_statement_classes.Count -ne 1 -or
        $sql.allowed_statement_classes[0] -ne 'SELECT' -or
        $sql.total_statements -gt 24 -or
        $sql.total_statements -ne $sql.statement_counts.SELECT -or
        $sql.rejected_statements -ne 0 -or
        $sql.persistent_db_write -ne $false -or
        $sql.ddl -ne $false -or
        $sql.migration_execution -ne $false) {
        return $false
    }
    if ($Json -match '(?i)DB_PASSWORD|DB_USERNAME|DB_HOST|APP_KEY|AUTHORIZATION|PRIVATE KEY|BEGIN RSA|BEGIN OPENSSH|exception_message|raw_path|organization_id|user_id') {
        return $false
    }
    return $true
}

function Assert-HelperContract {
    $source = Get-Content -Raw -LiteralPath $PSCommandPath
    foreach ($required in @(
        'BatchMode=yes','StrictHostKeyChecking=yes','NumberOfPasswordPrompts=0','ConnectionAttempts=1',
        'ClearAllForwardings=yes','ForwardAgent=no','PermitLocalCommand=no','STEP_RETRY_FORBIDDEN',
        'none_read_only_g2_migration_preflight','production_mutation = $false','retry_performed = $false'
    )) {
        if (-not $source.Contains($required)) { Stop-G2 'G2_HELPER_CONTRACT_INCOMPLETE' }
    }
    $forbiddenOperations = @(
        ('s' + 'cp.exe'), ('sf' + 'tp '), ('mk' + 'dir '), ('to' + 'uch '),
        ('ch' + 'mod '), ('ch' + 'own '), ('r' + 'm -'), ('m' + 'v '),
        ('tar ' + '-x'), ('migrate ' + '--force'), ('git ' + 'push'), ('ln ' + '-s')
    )
    foreach ($forbidden in $forbiddenOperations) {
        if ($source.Contains($forbidden)) { Stop-G2 'G2_REMOTE_MUTATION_PRESENT' }
    }
}

$repositoryRoot = $null
try {
    $repositoryRoot = Get-RepositoryRoot
    $script:FailureStage = 'HELPER_INTEGRITY'
    Assert-HelperContract
    $preconditions = Get-LocalPreconditions -RepositoryRoot $repositoryRoot

    if ($VerifyOnly) {
        $phpSource = Get-Content -Raw -LiteralPath $preconditions.PhpScriptPath
        $remote = Get-RemoteScript -PhpSource $phpSource
        $remoteForbiddenOperations = @(
            ('mk' + 'dir '), ('to' + 'uch '), ('ch' + 'mod '), ('ch' + 'own '),
            ('r' + 'm -'), ('m' + 'v '), ('c' + 'p '), ('s' + 'cp '),
            ('sf' + 'tp '), ('tar ' + '-x'), ('my' + 'sql '), ('maria' + 'db ')
        )
        foreach ($forbidden in $remoteForbiddenOperations) {
            if ($remote.Contains($forbidden)) { Stop-G2 'G2_SELF_TEST_REMOTE_MUTATION_PRESENT' }
        }
        Write-Output 'G2_HELPER_VERIFY=PASS'
        Write-Output ('candidate=' + $script:Candidate)
        Write-Output ('helper_sha256=' + (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant())
        Write-Output ('preflight_php_sha256=' + $script:PreflightPhpSha256)
        Write-Output 'r0_g1_evidence_binding_verified=true'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }

    if ($VerifyLocalPreconditionsOnly) {
        Write-Output 'G2_LOCAL_PRECONDITIONS=PASS'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }

    $executionGeneration = if ($Corrective2) { 'corrective-2' } elseif ($Corrective1) { 'corrective-1' } else { 'initial' }
    $rootName = if ($Corrective2) {
        'production-g2-migration-preflight-corrective-2-' + $script:Candidate
    }
    elseif ($Corrective1) {
        'production-g2-migration-preflight-corrective-1-' + $script:Candidate
    }
    else {
        'production-g2-migration-preflight-' + $script:Candidate
    }
    $evidenceRoot = Join-Path $repositoryRoot ('storage\app\release-audit\' + $rootName)
    $script:StatePath = Join-Path $evidenceRoot 'execution-state.json'
    $script:FailureStage = 'ATTEMPT_GUARD'
    if (Test-Path -LiteralPath $script:StatePath) { Stop-G2 'STEP_RETRY_FORBIDDEN' }
    if (-not (Test-Path -LiteralPath $evidenceRoot -PathType Container)) {
        New-Item -ItemType Directory -Path $evidenceRoot | Out-Null
    }
    $script:State = [pscustomobject]@{
        schema_version = 1
        candidate = $script:Candidate
        bundle_id = $script:BundleId
        execution_generation = $executionGeneration
        status = 'ATTEMPT_STARTED'
        helper_sha256 = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant()
        preflight_php_sha256 = $script:PreflightPhpSha256
        started_at_jst = Get-JstTimestamp
        completed_at_jst = $null
        failure_stage = $null
        safe_error_code = $null
        production_connection_attempted = $false
        production_mutation = $false
        retry_performed = $false
        remote_exit_code = $null
        stderr_sha256 = $null
        stderr_bytes = 0
        remote_safe_error_code = $null
        remote_stdout_contract_status = 'not_inspected'
    }
    Save-G2State
    $script:AttemptStarted = $true

    $script:FailureStage = 'PRODUCTION_READ_ONLY_G2_PREFLIGHT'
    $script:ProductionConnectionAttempted = $true
    $script:State.production_connection_attempted = $true
    Save-G2State
    $sshArguments = @(
        '-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ClearAllForwardings=yes','-o','ForwardAgent=no','-o','PermitLocalCommand=no',
        '-o',('UserKnownHostsFile=' + $preconditions.KnownHostsPath),$script:SshAlias,'bash -s'
    )
    $phpSource = Get-Content -Raw -LiteralPath $preconditions.PhpScriptPath
    $result = Invoke-CapturedProcess -FilePath $preconditions.SshPath -Arguments $sshArguments -StandardInput (Get-RemoteScript -PhpSource $phpSource)
    $script:State.remote_exit_code = $result.ExitCode
    $script:State.stderr_sha256 = if ([string]::IsNullOrEmpty($result.Stderr)) { $null } else { Get-Sha256Text $result.Stderr }
    $script:State.stderr_bytes = if ([string]::IsNullOrEmpty($result.Stderr)) { 0 } else { [Text.Encoding]::UTF8.GetByteCount($result.Stderr) }
    Save-G2State
    $evidencePath = Join-Path $evidenceRoot 'g2-preflight-evidence.json'
    $safePass = Test-SafePassEvidence -Json $result.Stdout
    $safeFailure = Test-SafeFailureEvidence -Json $result.Stdout
    if ($safePass -or $safeFailure) {
        [IO.File]::WriteAllText($evidencePath, $result.Stdout, [Text.UTF8Encoding]::new($false))
        $script:State.remote_stdout_contract_status = if ($safePass) { 'safe_pass' } else { 'safe_failure' }
        if ($safeFailure) {
            $remoteFailure = $result.Stdout | ConvertFrom-Json
            $script:State.remote_safe_error_code = $remoteFailure.failure.safe_error_code
        }
        Save-G2State
    }
    else {
        $script:State.remote_stdout_contract_status = 'rejected'
        Save-G2State
    }
    if ($safeFailure) {
        Stop-G2 'G2_REMOTE_REPORTED_STOP'
    }
    if (-not [string]::IsNullOrEmpty($result.Stderr)) {
        if ($safePass) { Stop-G2 'G2_SAFE_PASS_WITH_STDERR' }
        Stop-G2 'G2_REMOTE_PREFLIGHT_FAILED'
    }
    if ($result.ExitCode -ne 0) {
        Stop-G2 'G2_REMOTE_PREFLIGHT_FAILED'
    }
    if (-not $safePass) {
        Stop-G2 'G2_EVIDENCE_OUTPUT_REJECTED'
    }

    $script:State.status = 'PASS'
    $script:State.completed_at_jst = Get-JstTimestamp
    Save-G2State

    Write-Output 'G2_MIGRATION_SAFETY_PREFLIGHT=PASS'
    Write-Output 'production_change_scope=none_read_only_g2_migration_preflight'
    Write-Output 'retry_available=false'
    Write-Output 'secret_output=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 0
}
catch {
    $safeErrorCode = if ($_.Exception.Message -match '^[A-Z0-9_]+$') { $_.Exception.Message } else { 'UNEXPECTED_LOCAL_FAILURE' }
    if ($script:AttemptStarted -and $null -ne $script:State) {
        $script:State.status = 'STOP'
        $script:State.completed_at_jst = Get-JstTimestamp
        $script:State.failure_stage = $script:FailureStage
        $script:State.safe_error_code = $safeErrorCode
        $script:State.production_connection_attempted = $script:ProductionConnectionAttempted
        $script:State.production_mutation = $false
        if ([string]::IsNullOrWhiteSpace([string] $script:State.stderr_sha256)) {
            $script:State.stderr_sha256 = Get-Sha256Text $_.Exception.Message
            $script:State.stderr_bytes = [Text.Encoding]::UTF8.GetByteCount($_.Exception.Message)
        }
        Save-G2State
    }
    elseif ($null -ne $repositoryRoot -and -not $VerifyOnly) {
        $receipt = [pscustomobject]@{
            schema_version = 1
            candidate = $script:Candidate
            status = 'STOP'
            safe_error_code = $safeErrorCode
            failure_stage = $script:FailureStage
            exception_message_sha256 = Get-Sha256Text $_.Exception.Message
            production_connection_attempted = $script:ProductionConnectionAttempted
            production_mutation = $false
            raw_exception_stored = $false
            recorded_at_jst = Get-JstTimestamp
        }
        Write-JsonAtomically -Path (Join-Path $repositoryRoot 'storage\app\release-audit\g2-helper-preflight-failure.json') -Value $receipt
    }
    Write-G2Stop -SafeErrorCode $safeErrorCode
    exit 1
}
