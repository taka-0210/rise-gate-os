[CmdletBinding()]
param([switch] $VerifyOnly)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$script:ArtifactSha256 = 'b055585e09d7ae00c65bcaacad213fc4c92d260d4f8e2125884fe72d3cfb6b99'
$script:ManifestSha256 = 'f0abf01a18ae1e2bd0b2fb79197eb42c8570e62794da570453f30e603cb89dfc'
$script:R0BundleSha256 = 'a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d'
$script:R0ManifestSha256 = 'a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7'
$script:R0StateSha256 = '159392dde20356febf20ea744953c7b901696e5c0f7dc5f45d1e2ae914f13b18'
$script:IsolatedEvidenceSha256 = 'ebebba036e01ae72333f4d446ae337d1ff89721ceac794a6bed435f4795edc86'
$script:SshAlias = 'company-os-production'
$script:IdentityFile = 'codex-company-os-production'
$script:FailureStage = 'BOOTSTRAP'
$script:ProductionConnectionAttempted = $false
$script:ProductionMutationPossible = $false
$script:State = $null
$script:StatePath = $null

function Get-JstTimestamp {
    return [DateTimeOffset]::UtcNow.ToOffset([TimeSpan]::FromHours(9)).ToString('o')
}

function Get-Sha256Text([AllowEmptyString()][string] $Value) {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value)))).Replace('-', '').ToLowerInvariant() }
    finally { $algorithm.Dispose() }
}

function Stop-Placement([Parameter(Mandatory = $true)][string] $Code) {
    throw [InvalidOperationException]::new($Code)
}

function Save-State {
    if ($null -eq $script:State -or [string]::IsNullOrWhiteSpace($script:StatePath)) { return }
    $json = $script:State | ConvertTo-Json -Depth 8
    [IO.File]::WriteAllText($script:StatePath, $json+[Environment]::NewLine, [Text.UTF8Encoding]::new($false))
}

function Complete-State([string] $Status, [string] $Code, [Nullable[int]] $RemoteExit, [string] $Stderr) {
    if ($null -eq $script:State) { return }
    $script:State.status = $Status
    $script:State.completed_at_jst = Get-JstTimestamp
    $script:State.failure_stage = $script:FailureStage
    $script:State.safe_error_code = if ($Status -eq 'STOP') { $Code } else { $null }
    $script:State.remote_exit_code = $RemoteExit
    $script:State.stderr_sha256 = if ([string]::IsNullOrEmpty($Stderr)) { $null } else { Get-Sha256Text $Stderr }
    $script:State.stderr_bytes = if ([string]::IsNullOrEmpty($Stderr)) { 0 } else { [Text.Encoding]::UTF8.GetByteCount($Stderr) }
    $script:State.production_connection_attempted = $script:ProductionConnectionAttempted
    $script:State.production_mutation_possible = $script:ProductionMutationPossible
    Save-State
}

function Assert-NativeArguments([Parameter(Mandatory = $true)][string[]] $Arguments) {
    foreach ($argument in $Arguments) {
        if ([string]::IsNullOrWhiteSpace($argument) -or $argument -match '\s') { Stop-Placement 'NATIVE_ARGUMENT_REJECTED' }
    }
}

function Invoke-Utf8CapturedProcess {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [Parameter(Mandatory = $true)][string[]] $Arguments,
        [AllowNull()][string] $StandardInput
    )
    Assert-NativeArguments $Arguments
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
        if (-not $process.Start()) { Stop-Placement 'NATIVE_PROCESS_START_FAILED' }
        if ($null -ne $StandardInput) {
            $bytes = [Text.UTF8Encoding]::new($false).GetBytes($StandardInput)
            $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
            $process.StandardInput.BaseStream.Flush()
        }
        $process.StandardInput.Close()
        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode = $process.ExitCode; Stdout = $stdout; Stderr = $stderr }
    }
    finally { $process.Dispose() }
}

function Get-RepositoryRoot {
    return [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
}

function Assert-FileHash([string] $Path, [string] $Expected, [string] $Code) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Stop-Placement $Code }
    if ((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Expected) { Stop-Placement $Code }
}

function Get-CommonArguments {
    return @(
        '-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ConnectTimeout=10','-o','ClearAllForwardings=yes',
        '-o','ForwardAgent=no','-o','PermitLocalCommand=no','-o','LogLevel=ERROR'
    )
}

function Get-PrecheckScript {
    return (@'
set -eu
ACTUAL_HOME=$(cd "$HOME" && pwd -P)
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac
CANDIDATE_DIR="$ACTUAL_HOME/.ir1-r0-audit/924af91188cc60d33ff87c91b94ecc1d539566e6"
R0_ARCHIVE="$CANDIDATE_DIR/ir1-r0-audit-bundle-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz"
R0_BUNDLE="$CANDIDATE_DIR/bundle"
TARGET="$CANDIDATE_DIR/g2-preflight-v2"
test -d "$CANDIDATE_DIR"
test ! -L "$CANDIDATE_DIR"
test -f "$R0_ARCHIVE"
test ! -L "$R0_ARCHIVE"
printf '%s  %s\n' a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d "$R0_ARCHIVE" | sha256sum --check --strict - >/dev/null
test -d "$R0_BUNDLE"
test ! -L "$R0_BUNDLE"
test -f "$R0_BUNDLE/r0-bundle-manifest.json"
printf '%s  %s\n' a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7 "$R0_BUNDLE/r0-bundle-manifest.json" | sha256sum --check --strict - >/dev/null
test ! -e "$TARGET"
umask 077
mkdir "$TARGET"
test -d "$TARGET"
test ! -L "$TARGET"
test "$(find "$TARGET" -mindepth 1 -maxdepth 1 -print | wc -l | tr -d '[:space:]')" = 0
printf 'G2_V2_PLACEMENT_PREPARE=PASS\n'
printf 'overwrite_performed=false\n'
'@ -replace "`r`n", "`n").TrimEnd()+"`n"
}

function Get-FinalizeScript {
    return (@'
set -eu
ACTUAL_HOME=$(cd "$HOME" && pwd -P)
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac
TARGET="$ACTUAL_HOME/.ir1-r0-audit/924af91188cc60d33ff87c91b94ecc1d539566e6/g2-preflight-v2"
ARCHIVE="$TARGET/ir1-g2-preflight-v2-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz"
PACKAGE="$TARGET/package"
test -d "$TARGET"
test ! -L "$TARGET"
test "$(find "$TARGET" -mindepth 1 -maxdepth 1 -print | wc -l | tr -d '[:space:]')" = 1
test -f "$ARCHIVE"
test ! -L "$ARCHIVE"
printf '%s  %s\n' b055585e09d7ae00c65bcaacad213fc4c92d260d4f8e2125884fe72d3cfb6b99 "$ARCHIVE" | sha256sum --check --strict - >/dev/null
ENTRIES=$(tar --list --gzip --file "$ARCHIVE" | LC_ALL=C sort)
EXPECTED=$(printf '%s\n' './' './auditor.php' './checksums.sha256' './launcher.sh' './manifest.json' | LC_ALL=C sort)
test "$ENTRIES" = "$EXPECTED"
test "$(tar --list --verbose --gzip --file "$ARCHIVE" | awk 'substr($1,1,1)=="l" {n++} END {print n+0}')" = 0
test ! -e "$PACKAGE"
umask 077
mkdir "$PACKAGE"
tar --extract --gzip --file "$ARCHIVE" --directory "$PACKAGE" --no-same-owner --no-same-permissions
test -d "$PACKAGE"
test ! -L "$PACKAGE"
test "$(find "$PACKAGE" -type l -print | wc -l | tr -d '[:space:]')" = 0
test "$(find "$PACKAGE" -mindepth 1 -maxdepth 1 -type f -print | wc -l | tr -d '[:space:]')" = 4
test "$(find "$PACKAGE" -mindepth 1 -maxdepth 1 -type d -print | wc -l | tr -d '[:space:]')" = 0
test ! -e "$PACKAGE/.env"
printf '%s  %s\n' f0abf01a18ae1e2bd0b2fb79197eb42c8570e62794da570453f30e603cb89dfc "$PACKAGE/manifest.json" | sha256sum --check --strict - >/dev/null
(cd "$PACKAGE" && sha256sum --check --strict checksums.sha256 >/dev/null)
grep -F '"source_commit": "924af91188cc60d33ff87c91b94ecc1d539566e6"' "$PACKAGE/manifest.json" >/dev/null
test "$(find "$TARGET" -mindepth 1 -maxdepth 1 -print | wc -l | tr -d '[:space:]')" = 2
printf 'G2_V2_PLACEMENT_FINALIZE=PASS\n'
printf 'candidate_verified=true\n'
printf 'artifact_sha256_verified=true\n'
printf 'manifest_sha256_verified=true\n'
printf 'archive_file_set_verified=true\n'
printf 'bundle_symlink_count=0\n'
printf 'overwrite_performed=false\n'
'@ -replace "`r`n", "`n").TrimEnd()+"`n"
}

function Assert-ExactOutput([string] $Actual, [string[]] $Expected) {
    $lines = @($Actual -split "`r?`n" | Where-Object { $_ -ne '' })
    if (($lines -join "`n") -ne ($Expected -join "`n")) { Stop-Placement 'REMOTE_OUTPUT_CONTRACT_MISMATCH' }
}

function Assert-SelfContract {
    $precheck = Get-PrecheckScript
    $finalize = Get-FinalizeScript
    if ($precheck.Contains("`r") -or $finalize.Contains("`r")) { Stop-Placement 'REMOTE_SCRIPT_NOT_LF_ONLY' }
    foreach ($required in @($script:Candidate, $script:R0BundleSha256, $script:R0ManifestSha256, 'test ! -e "$TARGET"', 'mkdir "$TARGET"')) {
        if (-not $precheck.Contains($required)) { Stop-Placement 'PRECHECK_CONTRACT_INCOMPLETE' }
    }
    foreach ($required in @($script:Candidate, $script:ArtifactSha256, $script:ManifestSha256, 'sha256sum --check --strict checksums.sha256', 'mkdir "$PACKAGE"', 'tar --extract --gzip')) {
        if (-not $finalize.Contains($required)) { Stop-Placement 'FINALIZE_CONTRACT_INCOMPLETE' }
    }
    $combined = $precheck+"`n"+$finalize
    foreach ($forbidden in @('php ', 'artisan', 'mysql', 'mariadb', '.env" -', 'chmod ', 'chown ', 'rm ', 'rmdir ', 'mv ', 'ln ', 'touch ')) {
        if ($combined.Contains($forbidden)) { Stop-Placement 'PLACEMENT_SCOPE_EXPANDED' }
    }
    if (@($precheck.Split([char]10) | Where-Object { $_.Trim().StartsWith('mkdir ') }).Count -ne 1 -or
        @($finalize.Split([char]10) | Where-Object { $_.Trim().StartsWith('mkdir ') }).Count -ne 1 -or
        @($finalize.Split([char]10) | Where-Object { $_.Trim().StartsWith('tar --extract ') }).Count -ne 1) {
        Stop-Placement 'PLACEMENT_MUTATION_COUNT_MISMATCH'
    }
}

function Get-LocalPreconditions([string] $Root) {
    $script:FailureStage = 'LOCAL_ARTIFACT_BINDING'
    $artifactName = 'ir1-g2-preflight-v2-'+$script:Candidate+'.tar.gz'
    $artifact = Join-Path $Root ('storage\app\release-audit\'+$artifactName)
    $manifest = $artifact+'.manifest.json'
    Assert-FileHash $artifact $script:ArtifactSha256 'ARTIFACT_IDENTITY_MISMATCH'
    Assert-FileHash $manifest $script:ManifestSha256 'MANIFEST_IDENTITY_MISMATCH'
    $isolatedEvidence = Join-Path $Root 'storage\app\release-audit\g2-preflight-v2-isolated-verification.json'
    Assert-FileHash $isolatedEvidence $script:IsolatedEvidenceSha256 'ISOLATED_EVIDENCE_MISMATCH'
    try {
        $manifestData = Get-Content -Raw -LiteralPath $manifest | ConvertFrom-Json
        $isolatedData = Get-Content -Raw -LiteralPath $isolatedEvidence | ConvertFrom-Json
    } catch { Stop-Placement 'LOCAL_EVIDENCE_INVALID' }
    if ($manifestData.source_commit -ne $script:Candidate -or $manifestData.schema_version -ne 2 -or
        $isolatedData.status -ne 'PASS' -or $isolatedData.exact_artifact_sha256 -ne $script:ArtifactSha256 -or
        $isolatedData.production_connection -ne $false -or $isolatedData.production_mutation -ne $false) {
        Stop-Placement 'LOCAL_EVIDENCE_CONTRACT_MISMATCH'
    }

    $script:FailureStage = 'R0_COMPLETION_BINDING'
    $r0StatePath = Join-Path $Root ('storage\app\release-audit\production-r0-human-'+$script:Candidate+'-corrective-2\execution-state.json')
    Assert-FileHash $r0StatePath $script:R0StateSha256 'R0_STATE_IDENTITY_MISMATCH'
    try { $r0State = Get-Content -Raw -LiteralPath $r0StatePath | ConvertFrom-Json } catch { Stop-Placement 'R0_STATE_INVALID' }
    $stepSix = @($r0State.attempts | Where-Object { $_.step -eq 6 }) | Select-Object -Last 1
    if ($r0State.candidate -ne $script:Candidate -or $r0State.last_step -ne 6 -or $r0State.last_status -ne 'PASS' -or $stepSix.status -ne 'PASS') {
        Stop-Placement 'R0_COMPLETION_CONTRACT_MISMATCH'
    }

    $script:FailureStage = 'LOCAL_OPENSSH_CONTRACT'
    $ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue
    $scp = Get-Command scp.exe -ErrorAction SilentlyContinue
    $sshKeygen = Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $scp -or $null -eq $sshKeygen) { Stop-Placement 'OPENSSH_CLIENT_UNAVAILABLE' }
    $configResult = Invoke-Utf8CapturedProcess $ssh.Source @('-G',$script:SshAlias) $null
    if ($configResult.ExitCode -ne 0) { Stop-Placement 'SSH_CONFIG_UNAVAILABLE' }
    $config = @{}
    foreach ($line in ($configResult.Stdout -split "`r?`n")) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) { $config[$parts[0].ToLowerInvariant()] = $parts[1] }
    }
    foreach ($required in @('hostname','user','port','identityfile')) {
        if (-not $config.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($config[$required])) { Stop-Placement 'SSH_CONFIG_INCOMPLETE' }
    }
    if ((Split-Path -Leaf $config.identityfile.Trim('"')) -ne $script:IdentityFile -or $config.identitiesonly -ne 'yes') {
        Stop-Placement 'SSH_IDENTITY_CONTRACT_MISMATCH'
    }
    foreach ($directive in @('remotecommand','localcommand','proxycommand','proxyjump')) {
        if ($config.ContainsKey($directive) -and $config[$directive] -ne 'none') { Stop-Placement 'SSH_AUTOMATIC_COMMAND_FORBIDDEN' }
    }
    $knownHosts = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHosts -PathType Leaf)) { Stop-Placement 'KNOWN_HOSTS_MISSING' }
    $lookup = '['+$config.hostname+']:'+$config.port
    $known = Invoke-Utf8CapturedProcess $sshKeygen.Source @('-F',$lookup,'-f',$knownHosts) $null
    if ($known.ExitCode -ne 0) { Stop-Placement 'HOST_KEY_NOT_REGISTERED' }
    $types = @($known.Stdout -split "`r?`n" | Where-Object { $_ -and -not $_.StartsWith('#') } | ForEach-Object { ($_ -split '\s+')[1] } | Sort-Object -Unique)
    if (($types -join ',') -ne 'ecdsa-sha2-nistp256,ssh-ed25519,ssh-rsa') { Stop-Placement 'HOST_KEY_CONTRACT_MISMATCH' }
    return [pscustomobject]@{ ArtifactName=$artifactName; ArtifactPath=$artifact; SshPath=$ssh.Source; ScpPath=$scp.Source }
}

try {
    Assert-SelfContract
    $root = Get-RepositoryRoot
    $preconditions = Get-LocalPreconditions $root
    $evidenceRoot = Join-Path $root ('storage\app\release-audit\production-g2-preflight-v2-placement-'+$script:Candidate)
    if (Test-Path -LiteralPath $evidenceRoot) { Stop-Placement 'PLACEMENT_ATTEMPT_ALREADY_RECORDED' }
    if ($VerifyOnly) {
        Write-Output 'G2_V2_PLACEMENT_HELPER_VERIFY=PASS'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_mutation=false'
        exit 0
    }

    New-Item -ItemType Directory -Path $evidenceRoot | Out-Null
    $script:StatePath = Join-Path $evidenceRoot 'execution-state.json'
    $script:State = [pscustomobject]@{
        schema_version=1; candidate=$script:Candidate; artifact_sha256=$script:ArtifactSha256
        status='ATTEMPT_STARTED'; started_at_jst=Get-JstTimestamp; completed_at_jst=$null
        failure_stage='PRODUCTION_PLACEMENT_PRECHECK'; safe_error_code=$null; remote_exit_code=$null
        stderr_sha256=$null; stderr_bytes=0; production_connection_attempted=$false
        production_mutation_possible=$false; retry_performed=$false; overwrite_performed=$false
        db_connection_attempted=$false; sql_executed=$false; migration_executed=$false
        secret_output=$false
    }
    Save-State

    $common = Get-CommonArguments
    $script:FailureStage = 'PRODUCTION_PLACEMENT_PRECHECK'
    $script:ProductionConnectionAttempted = $true
    $script:ProductionMutationPossible = $true
    $precheck = Invoke-Utf8CapturedProcess $preconditions.SshPath (@($common)+@('-T',$script:SshAlias,'sh','-s')) (Get-PrecheckScript)
    if ($precheck.ExitCode -ne 0) { Complete-State 'STOP' 'PLACEMENT_PRECHECK_FAILED' $precheck.ExitCode $precheck.Stderr; Stop-Placement 'PLACEMENT_PRECHECK_FAILED' }
    Assert-ExactOutput $precheck.Stdout @('G2_V2_PLACEMENT_PREPARE=PASS','overwrite_performed=false')

    $script:FailureStage = 'PRODUCTION_ARTIFACT_UPLOAD'
    $destination = $script:SshAlias+':.ir1-r0-audit/'+$script:Candidate+'/g2-preflight-v2/'+$preconditions.ArtifactName
    $upload = Invoke-Utf8CapturedProcess $preconditions.ScpPath (@($common)+@($preconditions.ArtifactPath,$destination)) $null
    if ($upload.ExitCode -ne 0) { Complete-State 'STOP' 'ARTIFACT_UPLOAD_FAILED' $upload.ExitCode $upload.Stderr; Stop-Placement 'ARTIFACT_UPLOAD_FAILED' }

    $script:FailureStage = 'PRODUCTION_PLACEMENT_FINALIZE'
    $finalize = Invoke-Utf8CapturedProcess $preconditions.SshPath (@($common)+@('-T',$script:SshAlias,'sh','-s')) (Get-FinalizeScript)
    if ($finalize.ExitCode -ne 0) { Complete-State 'STOP' 'PLACEMENT_FINALIZE_FAILED' $finalize.ExitCode $finalize.Stderr; Stop-Placement 'PLACEMENT_FINALIZE_FAILED' }
    Assert-ExactOutput $finalize.Stdout @(
        'G2_V2_PLACEMENT_FINALIZE=PASS','candidate_verified=true','artifact_sha256_verified=true',
        'manifest_sha256_verified=true','archive_file_set_verified=true','bundle_symlink_count=0','overwrite_performed=false'
    )
    Complete-State 'PASS' $null $finalize.ExitCode $finalize.Stderr
    Write-Output 'G2_V2_PLACEMENT=PASS'
    Write-Output 'candidate_verified=true'
    Write-Output 'artifact_sha256_verified=true'
    Write-Output 'manifest_sha256_verified=true'
    Write-Output 'archive_file_set_verified=true'
    Write-Output 'bundle_symlink_count=0'
    Write-Output 'overwrite_performed=false'
    Write-Output 'production_change_scope=isolated_g2_v2_audit_artifact_placement_only'
    Write-Output 'retry_available=false'
    Write-Output 'secret_output=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 0
}
catch {
    $code = if ($_.Exception.Message -match '^[A-Z0-9_]+$') { $_.Exception.Message } else { 'UNEXPECTED_LOCAL_FAILURE' }
    Complete-State 'STOP' $code $null ''
    Write-Output 'G2_V2_PLACEMENT=STOP'
    Write-Output ('safe_error_code='+$code)
    Write-Output ('failure_stage='+$script:FailureStage)
    Write-Output ('production_connection_attempted='+$script:ProductionConnectionAttempted.ToString().ToLowerInvariant())
    Write-Output ('production_mutation_possible='+$script:ProductionMutationPossible.ToString().ToLowerInvariant())
    Write-Output 'retry_performed=false'
    Write-Output 'secret_output=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}
