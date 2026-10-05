[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [ValidateSet('Initial', 'Corrective1')][string] $Attempt = 'Initial'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$SshAlias = 'company-os-production'
$ExpectedHost = 'sv17033.xserver.jp'
$ExpectedPort = '10022'
$ExpectedIdentityLeaf = 'codex-company-os-production'
$ExpectedIdentityFingerprint = 'SHA256:d/dPUPa6KjfXVOpG1Sqqp66GMFjYtxYUR1dqhaRBQBg'
$ExpectedHostKeyFingerprint = 'SHA256:lkUHlNS7K7nVe/slV97qC08nvzqNzsgUsVD9p2Q1KCs'
$ExpectedScriptSha256 = '11796c27cdf80ff695ec8d0f4053b991ea793a408334c000b2c06bbcb9922f14'
$HelperGeneration = if ($Attempt -eq 'Corrective1') { 'g5b-target-discovery-corrective-1' } else { 'g5b-target-discovery-v1' }
$Utf8NoBom = [Text.UTF8Encoding]::new($false)

function Stop-G5B([string] $Code) { throw [InvalidOperationException]::new($Code) }
function Get-SafeErrorCode([Exception] $Exception) {
    if ($Exception.Message -match '^[A-Z][A-Z0-9_]{2,80}$') { return $Exception.Message }
    return 'UNEXPECTED_LOCAL_FAILURE'
}
function Get-TextSha256([string] $Value) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value)))).Replace('-', '').ToLowerInvariant()
    } finally { $sha.Dispose() }
}
function Get-FileSha256([string] $Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}
function Read-LfScript([string] $Path) {
    $text = [IO.File]::ReadAllText($Path)
    $crlf = [string]::Concat([char] 13, [char] 10)
    $cr = [string][char] 13
    $lf = [string][char] 10
    $normalized = $text.Replace($crlf, $lf).Replace($cr, $lf)
    if ($normalized.Contains([char] 13)) { Stop-G5B 'SCRIPT_LINE_ENDING_NORMALIZATION_FAILED' }
    return $normalized
}
function Assert-NativeArguments([string[]] $Arguments) {
    foreach ($argument in $Arguments) {
        if ([string]::IsNullOrWhiteSpace($argument) -or $argument -match '\s') { Stop-G5B 'NATIVE_ARGUMENT_REJECTED' }
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
        if (-not $process.Start()) { Stop-G5B 'NATIVE_PROCESS_START_FAILED' }
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
    } finally { $process.Dispose() }
}
function Parse-SafeOutput([string] $Value) {
    if ([Text.Encoding]::UTF8.GetByteCount($Value) -gt 32768 -or
        $Value -match '/home/[A-Za-z0-9._-]+' -or
        $Value -match '(?i)(DB_PASSWORD|APP_KEY|API[_-]?KEY|PRIVATE KEY|BEARER|CREDENTIAL)') {
        Stop-G5B 'UNSAFE_REMOTE_OUTPUT'
    }
    $map = [ordered]@{}
    foreach ($line in ($Value -split '\r?\n' | Where-Object { $_ -ne '' })) {
        if ($line -notmatch '^([a-zA-Z0-9_]+)=([a-zA-Z0-9_.:+/-]+)$') { Stop-G5B 'REMOTE_OUTPUT_CONTRACT_INVALID' }
        if ($map.Contains($Matches[1])) { Stop-G5B 'REMOTE_OUTPUT_DUPLICATE_KEY' }
        $map[$Matches[1]] = $Matches[2]
    }
    if (-not $map.Contains('G5B_TARGET_DISCOVERY')) { Stop-G5B 'REMOTE_STATUS_MISSING' }
    return $map
}
function Save-Json([string] $Path, [object] $Value) {
    [IO.File]::WriteAllText($Path, (($Value | ConvertTo-Json -Depth 8) + [Environment]::NewLine), $Utf8NoBom)
}

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ScriptPath = Join-Path $PSScriptRoot 'inspect-target-anchor-read-only.sh'
$InitialEvidenceRoot = Join-Path $Root "storage\app\release-audit\production-g5b-target-discovery-$Candidate"
$EvidenceRoot = if ($Attempt -eq 'Corrective1') {
    Join-Path $Root "storage\app\release-audit\production-g5b-target-discovery-corrective-1-$Candidate"
} else {
    $InitialEvidenceRoot
}
$ReceiptPath = Join-Path $EvidenceRoot 'read-only-target-discovery.json'
$ObservationPath = Join-Path $EvidenceRoot 'sanitized-target-observation.json'
$StatePath = Join-Path $EvidenceRoot 'execution-state.json'
$ConnectionAttempted = $false
$NativeProcessStarted = $false
$RemoteExitCode = $null
$State = $null

try {
    if (-not (Test-Path -LiteralPath $ScriptPath -PathType Leaf)) { Stop-G5B 'DISCOVERY_SCRIPT_MISSING' }
    if ((Get-FileSha256 $ScriptPath) -ne $ExpectedScriptSha256) { Stop-G5B 'DISCOVERY_SCRIPT_BINDING_MISMATCH' }
    $scriptText = Read-LfScript $ScriptPath
    if ($scriptText -match '(?m)^\s*(mkdir|rm|rmdir|mv|cp|ln|chmod|chown|touch|tee)\b' -or
        $scriptText -match '(?m)^\s*sed\s+.*\s-i(\s|$)') { Stop-G5B 'REMOTE_MUTATION_TOKEN_FORBIDDEN' }

    $ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue
    $keygen = Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $keygen) { Stop-G5B 'OPENSSH_CLIENT_UNAVAILABLE' }

    $configResult = Invoke-CapturedProcess $ssh.Source @('-G', $SshAlias) $null
    if ($configResult.ExitCode -ne 0) { Stop-G5B 'SSH_CONFIG_UNAVAILABLE' }
    $config = @{}
    foreach ($line in ($configResult.Stdout -split '\r?\n')) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) { $config[$parts[0].ToLowerInvariant()] = $parts[1] }
    }
    foreach ($key in @('hostname', 'user', 'port', 'identityfile', 'identitiesonly')) {
        if (-not $config.ContainsKey($key)) { Stop-G5B 'SSH_CONFIG_INCOMPLETE' }
    }
    if ($config.hostname -ne $ExpectedHost -or $config.port -ne $ExpectedPort -or
        (Split-Path -Leaf $config.identityfile.Trim('"')) -ne $ExpectedIdentityLeaf -or
        $config.identitiesonly -ne 'yes') { Stop-G5B 'SSH_BINDING_MISMATCH' }
    foreach ($key in @('remotecommand', 'localcommand', 'proxycommand', 'proxyjump')) {
        if ($config.ContainsKey($key) -and -not [string]::IsNullOrWhiteSpace($config[$key]) -and $config[$key] -ne 'none') {
            Stop-G5B 'SSH_AUTOMATIC_COMMAND_FORBIDDEN'
        }
    }

    $identityPath = $config.identityfile.Trim('"')
    if (-not (Test-Path -LiteralPath $identityPath -PathType Leaf)) { Stop-G5B 'SSH_IDENTITY_MISSING' }
    $identityResult = Invoke-CapturedProcess $keygen.Source @('-lf', $identityPath) $null
    if ($identityResult.ExitCode -ne 0 -or $identityResult.Stdout -notmatch [regex]::Escape($ExpectedIdentityFingerprint)) {
        Stop-G5B 'SSH_IDENTITY_FINGERPRINT_MISMATCH'
    }

    $knownHostsPath = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHostsPath -PathType Leaf)) { Stop-G5B 'KNOWN_HOSTS_MISSING' }
    $lookup = "[$ExpectedHost]:$ExpectedPort"
    $knownResult = Invoke-CapturedProcess $keygen.Source @('-F', $lookup, '-f', $knownHostsPath) $null
    if ($knownResult.ExitCode -ne 0) { Stop-G5B 'HOST_KEY_NOT_REGISTERED' }
    $knownKeyLines = (($knownResult.Stdout -split '\r?\n' | Where-Object { $_ -ne '' -and $_ -notmatch '^#' }) -join [Environment]::NewLine)
    $knownFingerprint = Invoke-CapturedProcess $keygen.Source @('-lf', '-') $knownKeyLines
    if ($knownFingerprint.ExitCode -ne 0 -or $knownFingerprint.Stdout -notmatch [regex]::Escape($ExpectedHostKeyFingerprint)) {
        Stop-G5B 'HOST_KEY_FINGERPRINT_MISMATCH'
    }

    if ($Attempt -eq 'Corrective1') {
        $initialStatePath = Join-Path $InitialEvidenceRoot 'execution-state.json'
        if (-not (Test-Path -LiteralPath $initialStatePath -PathType Leaf)) { Stop-G5B 'INITIAL_STOP_STATE_REQUIRED' }
        $initialState = Get-Content -Raw -LiteralPath $initialStatePath | ConvertFrom-Json
        if ($initialState.status -ne 'STOP' -or
            $initialState.safe_error_code -ne 'TARGET_ANCHOR_REBINDING_FAILED' -or
            -not [bool] $initialState.production_connection_attempted -or
            [bool] $initialState.production_mutation -or
            $initialState.remote_exit_code -ne 0 -or
            $initialState.remote_contract -ne 'complete') {
            Stop-G5B 'INITIAL_STOP_STATE_MISMATCH'
        }
    }

    if ($VerifyOnly) {
        Write-Output 'G5B_TARGET_DISCOVERY_VERIFY_ONLY=PASS'
        Write-Output "candidate=$Candidate"
        Write-Output "attempt=$Attempt"
        Write-Output "helper_generation=$HelperGeneration"
        Write-Output "script_sha256=$ExpectedScriptSha256"
        Write-Output "ssh_host=$ExpectedHost"
        Write-Output "ssh_port=$ExpectedPort"
        Write-Output "identity_fingerprint=$ExpectedIdentityFingerprint"
        Write-Output "host_key_fingerprint=$ExpectedHostKeyFingerprint"
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_mutation=false'
        exit 0
    }

    if ((Test-Path -LiteralPath $ReceiptPath -PathType Leaf) -or (Test-Path -LiteralPath $StatePath -PathType Leaf)) {
        Stop-G5B 'G5B_RETRY_FORBIDDEN'
    }
    if (-not (Test-Path -LiteralPath $EvidenceRoot)) { New-Item -ItemType Directory -Path $EvidenceRoot | Out-Null }
    $State = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='ATTEMPT_STARTED'
        production_connection_attempted=$false; production_mutation=$false; native_process_started=$false
        remote_exit_code=$null; stdout_sha256=$null; stdout_bytes=0; stderr_sha256=$null; stderr_bytes=0
        ssh_authentication='unknown'; remote_shell='unknown'; remote_contract='unknown'; raw_output_stored=$false
        sanitized_evidence=$null; target_binding_status='unknown'; target_binding_mismatches=@(); safe_error_code=$null
    }
    Save-Json $StatePath $State

    $arguments = @(
        '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'NumberOfPasswordPrompts=0',
        '-o', 'ConnectionAttempts=1', '-o', 'ConnectTimeout=10', '-o', 'ClearAllForwardings=yes',
        '-o', 'LogLevel=ERROR', '-o', 'HostKeyAlgorithms=ssh-ed25519', '-T', $SshAlias,
        'sh', '-s', '--', 'IR1-G5B-READ-ONLY-TARGET-DISCOVERY'
    )
    Assert-NativeArguments $arguments
    $ConnectionAttempted = $true
    $State.production_connection_attempted = $true
    Save-Json $StatePath $State
    $NativeProcessStarted = $true
    $State.native_process_started = $true
    Save-Json $StatePath $State
    $result = Invoke-CapturedProcess $ssh.Source $arguments $scriptText
    $RemoteExitCode = $result.ExitCode
    $State.remote_exit_code = $RemoteExitCode
    $State.stdout_sha256 = Get-TextSha256 $result.Stdout
    $State.stdout_bytes = [Text.Encoding]::UTF8.GetByteCount($result.Stdout)
    $State.stderr_sha256 = Get-TextSha256 $result.Stderr
    $State.stderr_bytes = [Text.Encoding]::UTF8.GetByteCount($result.Stderr)
    Save-Json $StatePath $State

    $parsed = Parse-SafeOutput $result.Stdout
    $State.sanitized_evidence = $parsed
    $State.ssh_authentication = 'established'
    $State.remote_shell = 'established'
    $State.remote_contract = if ($parsed.G5B_TARGET_DISCOVERY -eq 'PASS') { 'complete' } else { 'reported_stop' }
    Save-Json $StatePath $State
    if ($parsed.G5B_TARGET_DISCOVERY -ne 'PASS') { Stop-G5B 'REMOTE_REPORTED_STOP' }
    if ($result.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($result.Stderr)) { Stop-G5B 'REMOTE_STEP_FAILED' }

    $expectedBinding = [ordered]@{
        discovery_binding='actual_home_not_assumed'
        remote_host_fqdn=$ExpectedHost
        xserver_management_boundary='yes'
        home_environment_matches_actual='yes'
        target_domain_root_state='directory'
        target_public_parent_state='directory'
        target_public_entry_state='directory'
        target_topology_root_state='absent'
        target_domain_owner_matches_login='yes'
        target_public_entry_owner_matches_login='yes'
        domain_public_same_device='yes'
        legacy_physical_separation='yes'
        target_anchor_state='provisioned'
    }
    $bindingMismatches = @()
    foreach ($entry in $expectedBinding.GetEnumerator()) {
        if (-not $parsed.Contains($entry.Key) -or $parsed[$entry.Key] -ne $entry.Value) {
            $bindingMismatches += $entry.Key
        }
    }
    $State.target_binding_status = if ($bindingMismatches.Count -eq 0) { 'pass' } else { 'review_required' }
    $State.target_binding_mismatches = @($bindingMismatches)
    Save-Json $StatePath $State
    $observation = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration
        status='OBSERVED'; production_mutation=$false; database_connection='not_attempted'
        target_binding_status=$State.target_binding_status; target_binding_mismatches=@($bindingMismatches)
        evidence=$parsed
    }
    Save-Json $ObservationPath $observation
    if ($bindingMismatches.Count -ne 0) {
        Stop-G5B 'TARGET_ANCHOR_REBINDING_FAILED'
    }

    $receipt = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='PASS'
        script_sha256=$ExpectedScriptSha256
        ssh=[ordered]@{
            alias=$SshAlias; host=$ExpectedHost; port=[int]$ExpectedPort
            identity_fingerprint=$ExpectedIdentityFingerprint; host_key_algorithm='ssh-ed25519'
            host_key_fingerprint=$ExpectedHostKeyFingerprint; authentication='established'; remote_shell='established'
        }
        production=[ordered]@{ connection_attempted=$true; mutation=$false; database_connection='not_attempted' }
        stream=[ordered]@{
            remote_exit_code=$RemoteExitCode; stdout_sha256=(Get-TextSha256 $result.Stdout)
            stdout_bytes=[Text.Encoding]::UTF8.GetByteCount($result.Stdout)
            stderr_sha256=(Get-TextSha256 $result.Stderr); stderr_bytes=[Text.Encoding]::UTF8.GetByteCount($result.Stderr)
            raw_output_stored=$false
        }
        evidence=$parsed
    }
    Save-Json $ReceiptPath $receipt
    $State.status = 'PASS'
    $State.safe_error_code = $null
    Save-Json $StatePath $State
    Write-Output 'G5B_TARGET_DISCOVERY=PASS'
    Write-Output 'production_connection_attempted=true'
    Write-Output 'production_mutation=false'
    Write-Output 'posix_capability_rehearsal=not_executed'
    Write-Output 'next_action=RETURN_TO_HUMAN_G5C_GATE'
} catch {
    $safeCode = Get-SafeErrorCode $_.Exception
    if ($null -ne $State) {
        $State.status = 'STOP'
        $State.safe_error_code = $safeCode
        $State.production_connection_attempted = $ConnectionAttempted
        $State.production_mutation = $false
        $State.native_process_started = $NativeProcessStarted
        $State.remote_exit_code = $RemoteExitCode
        Save-Json $StatePath $State
    }
    Write-Output 'G5B_TARGET_DISCOVERY=STOP'
    Write-Output "safe_error_code=$safeCode"
    Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output 'production_mutation=false'
    Write-Output 'posix_capability_rehearsal=not_executed'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}
