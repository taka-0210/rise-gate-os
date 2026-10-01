[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [switch] $VerifyLocalPreconditionsOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$script:Corrective3StateSha256 = '83469c014b729870a9331894a8fd8eef25994103fa9246357afab077f0a61b70'
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
    try { return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Stop-Diagnostic {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)
    throw [System.InvalidOperationException]::new($SafeErrorCode)
}

function Write-JsonAtomically {
    param([Parameter(Mandatory = $true)][string] $Path, [Parameter(Mandatory = $true)] $Value)

    $json = $Value | ConvertTo-Json -Depth 12
    $temporaryPath = $Path + '.tmp'
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $Path -Force
}

function Save-State {
    if ($null -eq $script:State -or [string]::IsNullOrWhiteSpace($script:StatePath)) {
        Stop-Diagnostic 'G2_RUNTIME_STATE_NOT_INITIALIZED'
    }
    Write-JsonAtomically -Path $script:StatePath -Value $script:State
}

function Assert-FileHash {
    param([Parameter(Mandatory = $true)][string] $Path, [Parameter(Mandatory = $true)][string] $Sha256)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Stop-Diagnostic 'G2_RUNTIME_BOUND_EVIDENCE_MISSING' }
    if ((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Sha256) {
        Stop-Diagnostic 'G2_RUNTIME_BOUND_EVIDENCE_HASH_MISMATCH'
    }
}

function Invoke-Utf8CapturedProcess {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [Parameter(Mandatory = $true)][string[]] $Arguments,
        [AllowEmptyString()][string] $StandardInput
    )

    foreach ($argument in $Arguments) {
        if ($argument -match '[\s"]') { Stop-Diagnostic 'G2_RUNTIME_NATIVE_ARGUMENT_REJECTED' }
    }
    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $FilePath
    $startInfo.Arguments = $Arguments -join ' '
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    try {
        if (-not $process.Start()) { Stop-Diagnostic 'G2_RUNTIME_NATIVE_PROCESS_START_FAILED' }
        $inputBytes = [Text.UTF8Encoding]::new($false).GetBytes($StandardInput)
        $process.StandardInput.BaseStream.Write($inputBytes, 0, $inputBytes.Length)
        $process.StandardInput.BaseStream.Flush()
        $process.StandardInput.Close()
        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode = $process.ExitCode; Stdout = $stdout; Stderr = $stderr }
    }
    finally {
        $process.Dispose()
    }
}

function Get-RemoteDiagnosticScript {
    $remote = @'
set -u
runtime_stop() {
    printf '{"output_schema_version":1,"status":"INCONCLUSIVE","audit_mode":"read-only-runtime-diagnostic","failure":{"safe_error_code":"%s","failure_stage":"remote_runtime_boundary"},"evidence":{"ssh_remote_shell":true,"php_cli_discovery":%s,"php_interpreter_start":%s,"php_stdin_execution":%s,"fixed_stdout":%s,"exit_code_capture":%s},"secret_output":false,"raw_path_output":false,"raw_exception_output":false,"production_change_scope":"none_read_only_runtime_diagnostic"}\n' "$1" "$2" "$3" "$4" "$5" "$6"
    exit 1
}
G2_PHP="$(command -v php8.3 || command -v php8.2 || command -v php || true)"
test -n "$G2_PHP" || runtime_stop G2_RUNTIME_PHP_CLI_UNAVAILABLE false false false false true
"$G2_PHP" -r 'exit(PHP_SAPI === "cli" ? 0 : 7);' >/dev/null 2>&1 || runtime_stop G2_RUNTIME_PHP_INTERPRETER_FAILED true false false false true
G2_TOKEN="$(printf '%s' '<?php fwrite(STDOUT, "G2_RUNTIME_STDIN_OK");' | "$G2_PHP" 2>/dev/null)" || runtime_stop G2_RUNTIME_PHP_STDIN_FAILED true true false false true
test "$G2_TOKEN" = 'G2_RUNTIME_STDIN_OK' || runtime_stop G2_RUNTIME_FIXED_STDOUT_MISMATCH true true true false true
printf '{"output_schema_version":1,"status":"PASS","audit_mode":"read-only-runtime-diagnostic","failure":null,"evidence":{"ssh_remote_shell":true,"php_cli_discovery":true,"php_interpreter_start":true,"php_stdin_execution":true,"fixed_stdout":true,"exit_code_capture":true},"secret_output":false,"raw_path_output":false,"raw_exception_output":false,"production_change_scope":"none_read_only_runtime_diagnostic"}\n'
exit 0
'@
    $lf = [string][char]10
    $remote = $remote.Replace(([string][char]13 + [char]10), $lf).Replace(([string][char]13), $lf)
    return $remote.TrimEnd([char]10) + $lf
}

function Test-SafeDiagnosticEvidence {
    param([AllowEmptyString()][string] $Json)

    if ([string]::IsNullOrEmpty($Json)) { return 'rejected' }
    try {
        $evidence = $Json | ConvertFrom-Json
        if ($evidence.output_schema_version -ne 1 -or
            $evidence.audit_mode -ne 'read-only-runtime-diagnostic' -or
            $evidence.production_change_scope -ne 'none_read_only_runtime_diagnostic' -or
            $evidence.secret_output -ne $false -or
            $evidence.raw_path_output -ne $false -or
            $evidence.raw_exception_output -ne $false -or
            $evidence.evidence.ssh_remote_shell -ne $true -or
            $evidence.evidence.exit_code_capture -ne $true) {
            return 'rejected'
        }
        if ($Json -match '(?i)DB_|APP_KEY|AUTHORIZATION|PRIVATE KEY|BEGIN RSA|BEGIN OPENSSH|exception_message|organization_id|user_id') {
            return 'rejected'
        }
        if ($evidence.status -eq 'PASS' -and
            $null -eq $evidence.failure -and
            $evidence.evidence.php_cli_discovery -eq $true -and
            $evidence.evidence.php_interpreter_start -eq $true -and
            $evidence.evidence.php_stdin_execution -eq $true -and
            $evidence.evidence.fixed_stdout -eq $true) {
            return 'safe_pass'
        }
        if ($evidence.status -eq 'INCONCLUSIVE' -and
            $evidence.failure.safe_error_code -match '^G2_RUNTIME_[A-Z0-9_]+$' -and
            $evidence.failure.failure_stage -eq 'remote_runtime_boundary') {
            return 'safe_failure'
        }
        return 'rejected'
    }
    catch { return 'rejected' }
}

function Assert-StaticContract {
    $source = Get-Content -Raw -LiteralPath $PSCommandPath
    foreach ($required in @(
        'BatchMode=yes','StrictHostKeyChecking=yes','NumberOfPasswordPrompts=0','ConnectionAttempts=1',
        'ClearAllForwardings=yes','ForwardAgent=no','PermitLocalCommand=no','STEP_RETRY_FORBIDDEN',
        'none_read_only_runtime_diagnostic','production_mutation = $false','retry_performed = $false'
    )) {
        if (-not $source.Contains($required)) { Stop-Diagnostic 'G2_RUNTIME_HELPER_CONTRACT_INCOMPLETE' }
    }
    $remote = Get-RemoteDiagnosticScript
    if ($remote.Contains([string][char]13) -or -not $remote.EndsWith([string][char]10)) {
        Stop-Diagnostic 'G2_RUNTIME_SCRIPT_NOT_LF_ONLY'
    }
    foreach ($forbidden in @('DB_', '.env', 'vendor/autoload', 'PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE TABLE', 'DROP TABLE', 'migrate', 'mkdir ', 'touch ', 'chmod ', 'chown ', 'rm ', 'mv ', 'tar ')) {
        if ($remote.Contains($forbidden)) { Stop-Diagnostic 'G2_RUNTIME_FORBIDDEN_BOUNDARY_PRESENT' }
    }
}

function Get-LocalPreconditions {
    $repositoryRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
    $corrective3State = Join-Path $repositoryRoot ('storage\app\release-audit\production-g2-migration-preflight-corrective-3-' + $script:Candidate + '\execution-state.json')
    Assert-FileHash -Path $corrective3State -Sha256 $script:Corrective3StateSha256
    $ssh = Get-Command 'ssh.exe' -ErrorAction SilentlyContinue
    $sshKeygen = Get-Command 'ssh-keygen.exe' -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $sshKeygen) { Stop-Diagnostic 'G2_RUNTIME_OPENSSH_UNAVAILABLE' }
    $configResult = & $ssh.Source -G $script:SshAlias 2>$null
    if ($LASTEXITCODE -ne 0) { Stop-Diagnostic 'G2_RUNTIME_SSH_CONFIG_UNAVAILABLE' }
    $config = @{}
    foreach ($line in $configResult) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) { $config[$parts[0].ToLowerInvariant()] = $parts[1] }
    }
    foreach ($required in @('hostname','user','port','identityfile')) {
        if (-not $config.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($config[$required])) {
            Stop-Diagnostic 'G2_RUNTIME_SSH_CONFIG_INCOMPLETE'
        }
    }
    if ((Split-Path -Leaf $config.identityfile.Trim('"')) -ne $script:IdentityFile -or $config.identitiesonly -ne 'yes') {
        Stop-Diagnostic 'G2_RUNTIME_SSH_IDENTITY_MISMATCH'
    }
    $knownHosts = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHosts -PathType Leaf)) { Stop-Diagnostic 'G2_RUNTIME_KNOWN_HOSTS_MISSING' }
    $lookup = '[' + $config.hostname + ']:' + $config.port
    & $sshKeygen.Source -F $lookup -f $knownHosts *> $null
    if ($LASTEXITCODE -ne 0) { Stop-Diagnostic 'G2_RUNTIME_HOST_KEY_MISSING' }
    return [pscustomobject]@{ RepositoryRoot = $repositoryRoot; SshPath = $ssh.Source; KnownHostsPath = $knownHosts }
}

function Write-Stop {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)
    Write-Output 'G2_REMOTE_RUNTIME_DIAGNOSTIC=STOP'
    Write-Output ('safe_error_code=' + $SafeErrorCode)
    Write-Output ('failure_stage=' + $script:FailureStage)
    Write-Output ('production_connection_attempted=' + $script:ProductionConnectionAttempted.ToString().ToLowerInvariant())
    Write-Output 'retry_performed=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
}

function Test-ExistingG2HeredocBoundary {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $helperPath = Join-Path $RepositoryRoot 'deployment\r0-audit\Invoke-G2MigrationSafetyPreflight.ps1'
    $phpPath = Join-Path $RepositoryRoot 'deployment\r0-audit\g2-migration-preflight.php'
    $tokens = $null
    $parseErrors = $null
    $ast = [Management.Automation.Language.Parser]::ParseFile($helperPath, [ref] $tokens, [ref] $parseErrors)
    if ($parseErrors.Count -gt 0) { return $false }
    $functionAst = $ast.Find({
        param($node)
        $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Get-RemoteScript'
    }, $true)
    if ($null -eq $functionAst) { return $false }
    . ([scriptblock]::Create($functionAst.Extent.Text))
    $existingRemote = Get-RemoteScript -PhpSource (Get-Content -Raw -LiteralPath $phpPath)
    $delimiterCrlf = '__G2_PHP_SOURCE_924AF911__' + [string][char]13 + [char]10
    return $existingRemote.Contains($delimiterCrlf)
}

try {
    Assert-StaticContract
    $preconditions = Get-LocalPreconditions
    if ($VerifyOnly) {
        $remote = Get-RemoteDiagnosticScript
        if (-not (Test-ExistingG2HeredocBoundary -RepositoryRoot $preconditions.RepositoryRoot)) {
            Stop-Diagnostic 'G2_RUNTIME_EXISTING_BOUNDARY_NOT_REPRODUCED'
        }
        $passFixture = '{"output_schema_version":1,"status":"PASS","audit_mode":"read-only-runtime-diagnostic","failure":null,"evidence":{"ssh_remote_shell":true,"php_cli_discovery":true,"php_interpreter_start":true,"php_stdin_execution":true,"fixed_stdout":true,"exit_code_capture":true},"secret_output":false,"raw_path_output":false,"raw_exception_output":false,"production_change_scope":"none_read_only_runtime_diagnostic"}'
        if ((Test-SafeDiagnosticEvidence -Json $passFixture) -ne 'safe_pass' -or
            (Test-SafeDiagnosticEvidence -Json '') -ne 'rejected') {
            Stop-Diagnostic 'G2_RUNTIME_VALIDATOR_REGRESSION'
        }
        $localPhp = Join-Path (Split-Path -Parent (Split-Path -Parent $preconditions.RepositoryRoot)) 'php\php.exe'
        if (-not (Test-Path -LiteralPath $localPhp -PathType Leaf)) {
            Stop-Diagnostic 'G2_RUNTIME_LOCAL_PHP_FIXTURE_MISSING'
        }
        $localProgram = '<?php echo ''G2_LOCAL_STDIN_OK'';'
        $localResult = Invoke-Utf8CapturedProcess -FilePath $localPhp -Arguments @('-n') -StandardInput $localProgram
        if ($localResult.ExitCode -ne 0 -or $localResult.Stdout -ne 'G2_LOCAL_STDIN_OK' -or
            -not [string]::IsNullOrEmpty($localResult.Stderr)) {
            Stop-Diagnostic 'G2_RUNTIME_LOCAL_STDIN_REGRESSION'
        }
        Write-Output 'G2_REMOTE_RUNTIME_DIAGNOSTIC_VERIFY=PASS'
        Write-Output ('candidate=' + $script:Candidate)
        Write-Output ('helper_sha256=' + (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant())
        Write-Output ('remote_script_sha256=' + (Get-Sha256Text $remote))
        Write-Output 'remote_script_lf_only=true'
        Write-Output 'existing_g2_heredoc_delimiter_crlf_detected=true'
        Write-Output 'local_php_stdin_transport=PASS'
        Write-Output 'database_boundary_absent=true'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }
    if ($VerifyLocalPreconditionsOnly) {
        Write-Output 'G2_REMOTE_RUNTIME_LOCAL_PRECONDITIONS=PASS'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }

    $evidenceRoot = Join-Path $preconditions.RepositoryRoot ('storage\app\release-audit\production-g2-remote-runtime-diagnostic-' + $script:Candidate)
    $script:StatePath = Join-Path $evidenceRoot 'execution-state.json'
    $script:FailureStage = 'ATTEMPT_GUARD'
    if (Test-Path -LiteralPath $script:StatePath) { Stop-Diagnostic 'STEP_RETRY_FORBIDDEN' }
    if (-not (Test-Path -LiteralPath $evidenceRoot -PathType Container)) { New-Item -ItemType Directory -Path $evidenceRoot | Out-Null }
    $script:State = [pscustomobject]@{
        schema_version = 1; candidate = $script:Candidate; status = 'ATTEMPT_STARTED'
        helper_sha256 = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant()
        started_at_jst = Get-JstTimestamp; completed_at_jst = $null; failure_stage = $null; safe_error_code = $null
        production_connection_attempted = $false; production_mutation = $false; retry_performed = $false
        remote_exit_code = $null; stdout_sha256 = $null; stdout_bytes = 0; stderr_sha256 = $null; stderr_bytes = 0
        stdout_contract_status = 'not_inspected'; remote_safe_error_code = $null; local_exception_sha256 = $null
    }
    Save-State
    $script:AttemptStarted = $true
    $script:FailureStage = 'PRODUCTION_READ_ONLY_RUNTIME_DIAGNOSTIC'
    $script:ProductionConnectionAttempted = $true
    $script:State.production_connection_attempted = $true
    Save-State
    $sshArguments = @(
        '-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ClearAllForwardings=yes','-o','ForwardAgent=no','-o','PermitLocalCommand=no',
        '-o',('UserKnownHostsFile=' + $preconditions.KnownHostsPath),$script:SshAlias,'bash','-s'
    )
    $result = Invoke-Utf8CapturedProcess -FilePath $preconditions.SshPath -Arguments $sshArguments -StandardInput (Get-RemoteDiagnosticScript)
    $script:State.remote_exit_code = $result.ExitCode
    $script:State.stdout_sha256 = if ([string]::IsNullOrEmpty($result.Stdout)) { $null } else { Get-Sha256Text $result.Stdout }
    $script:State.stdout_bytes = [Text.Encoding]::UTF8.GetByteCount($result.Stdout)
    $script:State.stderr_sha256 = if ([string]::IsNullOrEmpty($result.Stderr)) { $null } else { Get-Sha256Text $result.Stderr }
    $script:State.stderr_bytes = [Text.Encoding]::UTF8.GetByteCount($result.Stderr)
    $classification = Test-SafeDiagnosticEvidence -Json $result.Stdout
    $script:State.stdout_contract_status = $classification
    if ($classification -eq 'safe_failure') {
        $remoteEvidence = $result.Stdout | ConvertFrom-Json
        $script:State.remote_safe_error_code = $remoteEvidence.failure.safe_error_code
    }
    Save-State
    if ($classification -eq 'safe_pass' -or $classification -eq 'safe_failure') {
        [IO.File]::WriteAllText((Join-Path $evidenceRoot 'runtime-diagnostic-evidence.json'), $result.Stdout, [Text.UTF8Encoding]::new($false))
    }
    if ($classification -eq 'safe_failure') { Stop-Diagnostic 'G2_RUNTIME_REMOTE_REPORTED_STOP' }
    if ($classification -ne 'safe_pass') { Stop-Diagnostic 'G2_RUNTIME_OUTPUT_REJECTED' }
    if (-not [string]::IsNullOrEmpty($result.Stderr)) { Stop-Diagnostic 'G2_RUNTIME_STDERR_PRESENT' }
    if ($result.ExitCode -ne 0) { Stop-Diagnostic 'G2_RUNTIME_EXIT_NONZERO' }
    $script:State.status = 'PASS'
    $script:State.completed_at_jst = Get-JstTimestamp
    Save-State
    Write-Output 'G2_REMOTE_RUNTIME_DIAGNOSTIC=PASS'
    Write-Output 'production_change_scope=none_read_only_runtime_diagnostic'
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
        $script:State.local_exception_sha256 = Get-Sha256Text $_.Exception.Message
        Save-State
    }
    Write-Stop -SafeErrorCode $safeErrorCode
    exit 1
}
