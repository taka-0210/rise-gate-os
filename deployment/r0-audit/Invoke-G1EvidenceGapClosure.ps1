[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [switch] $VerifyLocalPreconditionsOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$script:BundleId = '5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0'
$script:R0StateSha256 = '159392dde20356febf20ea744953c7b901696e5c0f7dc5f45d1e2ae914f13b18'
$script:R0ApplicationEvidenceSha256 = '4cb2f91d7d12e1083e8edaee41a3a408d0b2577d46fa1c69ad7a978a83c84be9'
$script:R0HostEvidenceSha256 = '03f0a69dbeac35747082f06c34e2b492f03cd0bcc8ebd18dc251c81372d0a7ac'
$script:R0Step6HelperSha256 = '0c32ec4743201ba721cb93d74a6b1b479d6e53369f17ddc47575cc115af418cf'
$script:SshAlias = 'company-os-production'
$script:IdentityFile = 'codex-company-os-production'
$script:FailureStage = 'BOOTSTRAP'
$script:ProductionConnectionAttempted = $false
$script:AttemptStarted = $false

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

function Stop-G1 {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)

    throw [System.InvalidOperationException]::new($SafeErrorCode)
}

function Write-G1Stop {
    param([Parameter(Mandatory = $true)][string] $SafeErrorCode)

    Write-Output 'G1_EVIDENCE_GAP_CLOSURE=STOP'
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

    $json = $Value | ConvertTo-Json -Depth 10
    $temporaryPath = $Path + '.tmp'
    [IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $Path -Force
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

function Assert-R0EvidenceContract {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $r0Root = Join-Path $RepositoryRoot ('storage\app\release-audit\production-r0-human-' + $script:Candidate + '-corrective-2')
    $statePath = Join-Path $r0Root 'execution-state.json'
    $applicationPath = Join-Path $r0Root 'application-db.stdout.json'
    $hostPath = Join-Path $r0Root 'host.stdout.json'
    $expected = @{
        $statePath = $script:R0StateSha256
        $applicationPath = $script:R0ApplicationEvidenceSha256
        $hostPath = $script:R0HostEvidenceSha256
    }
    foreach ($path in $expected.Keys) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            Stop-G1 'R0_EVIDENCE_MISSING'
        }
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected[$path]) {
            Stop-G1 'R0_EVIDENCE_HASH_MISMATCH'
        }
    }

    try {
        $state = Get-Content -Raw -LiteralPath $statePath | ConvertFrom-Json
    }
    catch {
        Stop-G1 'R0_EVIDENCE_INVALID'
    }
    $applicationJson = Get-Content -Raw -LiteralPath $applicationPath
    $hostJson = Get-Content -Raw -LiteralPath $hostPath

    $step6 = @($state.attempts | Where-Object { [int] $_.step -eq 6 })
    if ($state.candidate -ne $script:Candidate -or
        $state.bundle_id -ne $script:BundleId -or
        $state.last_step -ne 6 -or
        $state.last_status -ne 'PASS' -or
        $step6.Count -ne 1 -or
        $step6[0].status -ne 'PASS' -or
        $step6[0].helper_sha256 -ne $script:R0Step6HelperSha256 -or
        $step6[0].remote_exit_code -ne 0 -or
        $step6[0].stderr_bytes -ne 0 -or
        $applicationJson -notmatch '"status"\s*:\s*"PASS"' -or
        $hostJson -notmatch '"status"\s*:\s*"PASS"') {
        Stop-G1 'R0_COMPLETION_CONTRACT_MISMATCH'
    }
}

function Get-LocalPreconditions {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $script:FailureStage = 'LOCAL_R0_EVIDENCE'
    Assert-R0EvidenceContract -RepositoryRoot $RepositoryRoot

    $script:FailureStage = 'LOCAL_OPENSSH_TOOL_DISCOVERY'
    $ssh = Get-Command 'ssh.exe' -ErrorAction SilentlyContinue
    $sshKeygen = Get-Command 'ssh-keygen.exe' -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $sshKeygen) {
        Stop-G1 'OPENSSH_CLIENT_UNAVAILABLE'
    }

    $script:FailureStage = 'LOCAL_SSH_CONFIG_PARSE'
    $configResult = Invoke-CapturedProcess -FilePath $ssh.Source -Arguments @('-G', $script:SshAlias) -StandardInput $null
    if ($configResult.ExitCode -ne 0) {
        Stop-G1 'SSH_CONFIG_UNAVAILABLE'
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
            Stop-G1 'SSH_CONFIG_INCOMPLETE'
        }
    }
    if ((Split-Path -Leaf $config.identityfile.Trim('"')) -ne $script:IdentityFile -or
        $config.identitiesonly -ne 'yes') {
        Stop-G1 'SSH_IDENTITY_CONTRACT_MISMATCH'
    }
    foreach ($directive in @('remotecommand', 'localcommand', 'proxycommand', 'proxyjump')) {
        if ($config.ContainsKey($directive) -and $config[$directive] -ne 'none') {
            Stop-G1 'SSH_AUTOMATIC_COMMAND_FORBIDDEN'
        }
    }

    $script:FailureStage = 'LOCAL_HOST_KEY_LOOKUP'
    $knownHosts = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHosts -PathType Leaf)) {
        Stop-G1 'KNOWN_HOSTS_MISSING'
    }
    $lookup = '[' + $config.hostname + ']:' + $config.port
    $knownResult = Invoke-CapturedProcess -FilePath $sshKeygen.Source -Arguments @('-F', $lookup, '-f', $knownHosts) -StandardInput $null
    if ($knownResult.ExitCode -ne 0) {
        Stop-G1 'HOST_KEY_NOT_REGISTERED'
    }
    $hostKeyTypes = @($knownResult.Stdout -split "`r?`n" | Where-Object { $_ -match '^\S+\s+(ssh-rsa|ecdsa-sha2-nistp256|ssh-ed25519)\s+' } | ForEach-Object { ($_ -split '\s+')[1] } | Sort-Object -Unique)
    if ($hostKeyTypes.Count -ne 3) {
        Stop-G1 'HOST_KEY_SET_INCOMPLETE'
    }

    return [pscustomobject]@{
        SshPath = $ssh.Source
        KnownHostsPath = $knownHosts
    }
}

function Get-RemoteInspectionScript {
    return @'
set -u
ACTUAL_HOME="$(cd -- "$HOME" && pwd -P)" || exit 40
case "$ACTUAL_HOME" in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac
APP="$ACTUAL_HOME/rise-gate.com/rise-gate-os"
PUBLIC="$ACTUAL_HOME/rise-gate.com/public_html/os.rise-gate.com"
MARKER="$ACTUAL_HOME/rise-gate.com/public_html/.rise-gate-deploy-revision"
test -d "$APP" || exit 42
test ! -L "$APP" || exit 43
test -d "$PUBLIC" || exit 44
test ! -L "$PUBLIC" || exit 45
test -f "$MARKER" || exit 46
test ! -L "$MARKER" || exit 47

MARKER_VALUE="$(tr -d '[:space:]' < "$MARKER")"
if printf '%s' "$MARKER_VALUE" | grep -Eq '^[0-9a-fA-F]{40}$'; then MARKER_STATE=valid_sha40; else MARKER_STATE=invalid; fi
MARKER_VALUE="$(printf '%s' "$MARKER_VALUE" | tr 'A-F' 'a-f')"
if test "$MARKER_STATE" != valid_sha40; then MARKER_VALUE=none; fi

MANIFEST_STATE=absent
MANIFEST_SHA=none
for MANIFEST in "$APP/.release-manifest.json" "$APP/release-manifest.json"; do
    if test -f "$MANIFEST" && test ! -L "$MANIFEST"; then
        MANIFEST_STATE=present_unparsed
        PHP_BIN="$(command -v php8.3 || command -v php8.2 || command -v php || true)"
        if test -n "$PHP_BIN"; then
            VALUE="$("$PHP_BIN" -r '$j=json_decode(file_get_contents($argv[1]),true); $v=$j["source_commit"]??$j["commit"]??$j["sha"]??""; if(is_string($v)&&preg_match("/^[0-9a-f]{40}$/i",$v)){echo strtolower($v);}' "$MANIFEST" 2>/dev/null || true)"
            if test -n "$VALUE"; then MANIFEST_STATE=valid_sha40; MANIFEST_SHA="$VALUE"; fi
        fi
        break
    fi
done

GIT_STATE=unavailable
GIT_SHA=none
if command -v git >/dev/null 2>&1 && { test -d "$APP/.git" || test -f "$APP/.git"; }; then
    VALUE="$(git -C "$APP" rev-parse --verify HEAD 2>/dev/null || true)"
    if printf '%s' "$VALUE" | grep -Eq '^[0-9a-fA-F]{40}$'; then
        GIT_STATE=valid_sha40
        GIT_SHA="$(printf '%s' "$VALUE" | tr 'A-F' 'a-f')"
    else
        GIT_STATE=invalid
    fi
fi

BINDING=unknown
if test "$MARKER_STATE" = valid_sha40 && test "$MANIFEST_STATE" = valid_sha40; then
    if test "$MARKER_VALUE" = "$MANIFEST_SHA"; then BINDING=marker_matches_manifest; else BINDING=marker_manifest_mismatch; fi
elif test "$MARKER_STATE" = valid_sha40 && test "$GIT_STATE" = valid_sha40; then
    if test "$MARKER_VALUE" = "$GIT_SHA"; then BINDING=marker_matches_git_head; else BINDING=marker_git_head_mismatch; fi
fi

CRITICAL_TOTAL=3
CRITICAL_MATCH=0
CRITICAL_MISSING=0
FP_INPUT=''
check_critical() {
    FILE="$1"
    EXPECTED="$2"
    if test ! -f "$FILE" || test -L "$FILE"; then CRITICAL_MISSING=$((CRITICAL_MISSING + 1)); FP_INPUT="$FP_INPUT|missing"; return; fi
    HASH="$(sha256sum "$FILE" | awk '{print $1}')"
    FP_INPUT="$FP_INPUT|$HASH"
    if test "$HASH" = "$EXPECTED"; then CRITICAL_MATCH=$((CRITICAL_MATCH + 1)); fi
}
check_critical "$APP/artisan" f91a517c0c5d3a904c3f390933964a5e08ec1fc68146882730daac4aca4ffcec
check_critical "$APP/bootstrap/app.php" 7d32e3b17f82133c39bc0e47fa160452c7f6b6c5bd1f645359b3822149b4d3f5
check_critical "$APP/composer.lock" 48cae0d80cc435e52cd55d2c96ac90ffc7f79ffb502d6c996a18e81fee4aa059
APPLICATION_FINGERPRINT="$(printf '%s' "$FP_INPUT" | sha256sum | awk '{print $1}')"

FOUND=''
add_backup() {
    ITEM="$1"
    if test -d "$ITEM" || test -L "$ITEM"; then
        case "|$FOUND|" in *"|$ITEM|"*) ;; *) FOUND="$FOUND|$ITEM" ;; esac
    fi
}
add_backup "$ACTUAL_HOME/rise-gate.com/public_html/_backup"
add_backup "$ACTUAL_HOME/rise-gate.com/public_html/os.rise-gate.com/_backup"
add_backup "$ACTUAL_HOME/rise-gate.com/_backup"
add_backup "$ACTUAL_HOME/public_html/_backup"
while IFS= read -r ITEM; do add_backup "$ITEM"; done < <(find "$ACTUAL_HOME/rise-gate.com" -xdev -maxdepth 5 \( -type d -o -type l \) -name _backup -print 2>/dev/null | head -n 8)
BACKUP_MATCH_COUNT=0
OLDIFS="$IFS"; IFS='|'
for ITEM in $FOUND; do if test -n "$ITEM"; then BACKUP_MATCH_COUNT=$((BACKUP_MATCH_COUNT + 1)); BACKUP_PATH="$ITEM"; fi; done
IFS="$OLDIFS"
BACKUP_STATE=absent
BACKUP_CLASS=none
BACKUP_MODE=none
BACKUP_PATH_SHA256=none
BACKUP_FILE_COUNT=0
BACKUP_TOTAL_BYTES=0
BACKUP_DB_CANDIDATE_COUNT=0
BACKUP_ARCHIVE_COUNT=0
BACKUP_NEWEST_EPOCH=0
BACKUP_OLDEST_EPOCH=0
BACKUP_INVENTORY_TRUNCATED=false
if test "$BACKUP_MATCH_COUNT" -eq 1; then
    case "$BACKUP_PATH" in
        "$ACTUAL_HOME/rise-gate.com/public_html/_backup") BACKUP_CLASS=legacy_public_parent ;;
        "$ACTUAL_HOME/rise-gate.com/public_html/os.rise-gate.com/_backup") BACKUP_CLASS=legacy_public_document_root ;;
        "$ACTUAL_HOME/rise-gate.com/_backup") BACKUP_CLASS=legacy_domain_root ;;
        "$ACTUAL_HOME/public_html/_backup") BACKUP_CLASS=home_public_root ;;
        *) BACKUP_CLASS=discovered_other_within_domain ;;
    esac
    BACKUP_PATH_SHA256="$(readlink -f "$BACKUP_PATH" 2>/dev/null | sha256sum | awk '{print $1}')"
    if test -L "$BACKUP_PATH"; then
        BACKUP_STATE=symlink_rejected
    elif test -d "$BACKUP_PATH" && test -r "$BACKUP_PATH"; then
        BACKUP_STATE=readable_directory
        BACKUP_MODE="$(stat -c '%a' "$BACKUP_PATH" 2>/dev/null || printf unknown)"
        while IFS= read -r -d '' FILE; do
            BACKUP_FILE_COUNT=$((BACKUP_FILE_COUNT + 1))
            if test "$BACKUP_FILE_COUNT" -gt 10000; then BACKUP_INVENTORY_TRUNCATED=true; break; fi
            SIZE="$(stat -c '%s' "$FILE" 2>/dev/null || printf 0)"
            MTIME="$(stat -c '%Y' "$FILE" 2>/dev/null || printf 0)"
            BACKUP_TOTAL_BYTES=$((BACKUP_TOTAL_BYTES + SIZE))
            if test "$MTIME" -gt "$BACKUP_NEWEST_EPOCH"; then BACKUP_NEWEST_EPOCH="$MTIME"; fi
            if test "$BACKUP_OLDEST_EPOCH" -eq 0 || test "$MTIME" -lt "$BACKUP_OLDEST_EPOCH"; then BACKUP_OLDEST_EPOCH="$MTIME"; fi
            LOWER="$(basename "$FILE" | tr 'A-Z' 'a-z')"
            case "$LOWER" in *.sql|*.sql.gz|*.sql.bz2|*.sql.xz|*.dump|*.sqlite|*.sqlite3|*.db) BACKUP_DB_CANDIDATE_COUNT=$((BACKUP_DB_CANDIDATE_COUNT + 1)) ;; esac
            case "$LOWER" in *.zip|*.tar|*.tar.gz|*.tgz|*.tar.bz2|*.tar.xz|*.gz|*.bz2|*.xz) BACKUP_ARCHIVE_COUNT=$((BACKUP_ARCHIVE_COUNT + 1)) ;; esac
        done < <(find "$BACKUP_PATH" -xdev -type f -print0 2>/dev/null)
        if test "$BACKUP_FILE_COUNT" -gt 10000; then BACKUP_FILE_COUNT=10000; fi
    else
        BACKUP_STATE=unreadable_or_non_directory
    fi
elif test "$BACKUP_MATCH_COUNT" -gt 1; then
    BACKUP_STATE=ambiguous_multiple
fi

CRONTAB_COMMAND=absent
CRONTAB_LIST_STATE=not_attempted
CRONTAB_EXIT_CODE=127
CRONTAB_ENTRY_COUNT=0
CRONTAB_SCHEDULE_COUNT=0
CRONTAB_LEGACY_APP_MATCH_COUNT=0
if command -v crontab >/dev/null 2>&1; then
    CRONTAB_COMMAND=present
    set +e
    CRON_OUTPUT="$(crontab -l 2>/dev/null)"
    CRONTAB_EXIT_CODE=$?
    set -e
    if test "$CRONTAB_EXIT_CODE" -eq 0; then
        CRONTAB_LIST_STATE=available
        while IFS= read -r LINE; do
            TRIMMED="$(printf '%s' "$LINE" | sed 's/^[[:space:]]*//')"
            case "$TRIMMED" in ''|'#'*) continue ;; esac
            CRONTAB_ENTRY_COUNT=$((CRONTAB_ENTRY_COUNT + 1))
            case "$LINE" in *artisan*schedule:run*|*artisan*schedule:work*) CRONTAB_SCHEDULE_COUNT=$((CRONTAB_SCHEDULE_COUNT + 1)) ;; esac
            case "$LINE" in *"$APP/artisan"*) CRONTAB_LEGACY_APP_MATCH_COUNT=$((CRONTAB_LEGACY_APP_MATCH_COUNT + 1)) ;; esac
        done <<EOF_CRON
$CRON_OUTPUT
EOF_CRON
    else
        CRONTAB_LIST_STATE=inaccessible_or_empty_account
    fi
fi

SCHEDULER_PROCESS_COUNT=0
QUEUE_PROCESS_COUNT=0
if test -d /proc; then
    for CMDLINE in /proc/[0-9]*/cmdline; do
        test -r "$CMDLINE" || continue
        VALUE="$(tr '\000' ' ' < "$CMDLINE" 2>/dev/null || true)"
        case "$VALUE" in *artisan*schedule:work*) SCHEDULER_PROCESS_COUNT=$((SCHEDULER_PROCESS_COUNT + 1)) ;; esac
        case "$VALUE" in *artisan*queue:work*) QUEUE_PROCESS_COUNT=$((QUEUE_PROCESS_COUNT + 1)) ;; esac
    done
fi

ENV_STATE=absent
ENV_MODE=none
ENV_OWNER_MATCH=false
ENV_GROUP_MATCH=false
if test -f "$APP/.env" && test ! -L "$APP/.env"; then
    ENV_STATE=regular_file
    ENV_MODE="$(stat -c '%a' "$APP/.env" 2>/dev/null || printf unknown)"
    APP_UID="$(stat -c '%u' "$APP" 2>/dev/null || printf x)"
    APP_GID="$(stat -c '%g' "$APP" 2>/dev/null || printf x)"
    ENV_UID="$(stat -c '%u' "$APP/.env" 2>/dev/null || printf y)"
    ENV_GID="$(stat -c '%g' "$APP/.env" 2>/dev/null || printf y)"
    if test "$APP_UID" = "$ENV_UID"; then ENV_OWNER_MATCH=true; fi
    if test "$APP_GID" = "$ENV_GID"; then ENV_GROUP_MATCH=true; fi
elif test -L "$APP/.env"; then
    ENV_STATE=symlink
fi

printf '%s\n' 'g1_remote_evidence=PASS'
printf 'marker_state=%s\n' "$MARKER_STATE"
printf 'marker_sha=%s\n' "$MARKER_VALUE"
printf 'release_manifest_state=%s\n' "$MANIFEST_STATE"
printf 'release_manifest_sha=%s\n' "$MANIFEST_SHA"
printf 'git_head_state=%s\n' "$GIT_STATE"
printf 'git_head_sha=%s\n' "$GIT_SHA"
printf 'marker_application_binding=%s\n' "$BINDING"
printf 'critical_file_total=%s\n' "$CRITICAL_TOTAL"
printf 'critical_file_candidate_match_count=%s\n' "$CRITICAL_MATCH"
printf 'critical_file_missing_count=%s\n' "$CRITICAL_MISSING"
printf 'application_fingerprint_sha256=%s\n' "$APPLICATION_FINGERPRINT"
printf 'backup_match_count=%s\n' "$BACKUP_MATCH_COUNT"
printf 'backup_state=%s\n' "$BACKUP_STATE"
printf 'backup_canonical_class=%s\n' "$BACKUP_CLASS"
printf 'backup_path_sha256=%s\n' "$BACKUP_PATH_SHA256"
printf 'backup_mode=%s\n' "$BACKUP_MODE"
printf 'backup_file_count=%s\n' "$BACKUP_FILE_COUNT"
printf 'backup_total_bytes=%s\n' "$BACKUP_TOTAL_BYTES"
printf 'backup_db_candidate_count=%s\n' "$BACKUP_DB_CANDIDATE_COUNT"
printf 'backup_archive_count=%s\n' "$BACKUP_ARCHIVE_COUNT"
printf 'backup_newest_epoch=%s\n' "$BACKUP_NEWEST_EPOCH"
printf 'backup_oldest_epoch=%s\n' "$BACKUP_OLDEST_EPOCH"
printf 'backup_inventory_truncated=%s\n' "$BACKUP_INVENTORY_TRUNCATED"
printf 'crontab_command=%s\n' "$CRONTAB_COMMAND"
printf 'crontab_list_state=%s\n' "$CRONTAB_LIST_STATE"
printf 'crontab_exit_code=%s\n' "$CRONTAB_EXIT_CODE"
printf 'crontab_entry_count=%s\n' "$CRONTAB_ENTRY_COUNT"
printf 'crontab_schedule_count=%s\n' "$CRONTAB_SCHEDULE_COUNT"
printf 'crontab_legacy_app_match_count=%s\n' "$CRONTAB_LEGACY_APP_MATCH_COUNT"
printf 'scheduler_process_count=%s\n' "$SCHEDULER_PROCESS_COUNT"
printf 'queue_process_count=%s\n' "$QUEUE_PROCESS_COUNT"
printf 'env_state=%s\n' "$ENV_STATE"
printf 'env_mode=%s\n' "$ENV_MODE"
printf 'env_owner_matches_application=%s\n' "$ENV_OWNER_MATCH"
printf 'env_group_matches_application=%s\n' "$ENV_GROUP_MATCH"
printf '%s\n' 'production_change_scope=none_read_only_g1_inspection'
printf '%s\n' 'secret_output=false'
'@
}

function ConvertFrom-FixedOutput {
    param([Parameter(Mandatory = $true)][string] $Value)

    $result = [ordered]@{}
    foreach ($line in ($Value -split "`r?`n")) {
        if ([string]::IsNullOrWhiteSpace($line)) { continue }
        if ($line -notmatch '^([a-z0-9_]+)=([A-Za-z0-9_.-]+)$') {
            Stop-G1 'REMOTE_OUTPUT_SCHEMA_REJECTED'
        }
        if ($result.Contains($Matches[1])) {
            Stop-G1 'REMOTE_OUTPUT_DUPLICATE_KEY'
        }
        $result[$Matches[1]] = $Matches[2]
    }

    $expectedKeys = @(
        'g1_remote_evidence','marker_state','marker_sha','release_manifest_state','release_manifest_sha',
        'git_head_state','git_head_sha','marker_application_binding','critical_file_total',
        'critical_file_candidate_match_count','critical_file_missing_count','application_fingerprint_sha256',
        'backup_match_count','backup_state','backup_canonical_class','backup_path_sha256','backup_mode',
        'backup_file_count','backup_total_bytes','backup_db_candidate_count','backup_archive_count',
        'backup_newest_epoch','backup_oldest_epoch','backup_inventory_truncated','crontab_command',
        'crontab_list_state','crontab_exit_code','crontab_entry_count','crontab_schedule_count',
        'crontab_legacy_app_match_count','scheduler_process_count','queue_process_count','env_state',
        'env_mode','env_owner_matches_application','env_group_matches_application','production_change_scope',
        'secret_output'
    )
    if ($result.Count -ne $expectedKeys.Count) {
        Stop-G1 'REMOTE_OUTPUT_KEY_COUNT_MISMATCH'
    }
    foreach ($key in $expectedKeys) {
        if (-not $result.Contains($key)) {
            Stop-G1 'REMOTE_OUTPUT_REQUIRED_KEY_MISSING'
        }
    }
    return $result
}

function Assert-RemoteEvidence {
    param([Parameter(Mandatory = $true)] $Evidence)

    if ($Evidence.g1_remote_evidence -ne 'PASS' -or
        $Evidence.production_change_scope -ne 'none_read_only_g1_inspection' -or
        $Evidence.secret_output -ne 'false') {
        Stop-G1 'REMOTE_EVIDENCE_HEADER_REJECTED'
    }
    foreach ($key in @('marker_sha','release_manifest_sha','git_head_sha','application_fingerprint_sha256','backup_path_sha256')) {
        $value = [string] $Evidence[$key]
        if ($value -ne 'none' -and $value -notmatch '^[0-9a-f]{40}$' -and $value -notmatch '^[0-9a-f]{64}$') {
            Stop-G1 'REMOTE_EVIDENCE_HASH_REJECTED'
        }
    }
    foreach ($key in @('critical_file_total','critical_file_candidate_match_count','critical_file_missing_count','backup_match_count','backup_file_count','backup_total_bytes','backup_db_candidate_count','backup_archive_count','backup_newest_epoch','backup_oldest_epoch','crontab_exit_code','crontab_entry_count','crontab_schedule_count','crontab_legacy_app_match_count','scheduler_process_count','queue_process_count')) {
        if ([string] $Evidence[$key] -notmatch '^\d+$') {
            Stop-G1 'REMOTE_EVIDENCE_NUMBER_REJECTED'
        }
    }
    foreach ($key in @('backup_inventory_truncated','env_owner_matches_application','env_group_matches_application')) {
        if ([string] $Evidence[$key] -notin @('true','false')) {
            Stop-G1 'REMOTE_EVIDENCE_BOOLEAN_REJECTED'
        }
    }
    $allowed = @{
        marker_state = @('valid_sha40','invalid')
        release_manifest_state = @('absent','present_unparsed','valid_sha40')
        git_head_state = @('unavailable','invalid','valid_sha40')
        marker_application_binding = @('unknown','marker_matches_manifest','marker_manifest_mismatch','marker_matches_git_head','marker_git_head_mismatch')
        backup_state = @('absent','readable_directory','symlink_rejected','unreadable_or_non_directory','ambiguous_multiple')
        backup_canonical_class = @('none','legacy_public_parent','legacy_public_document_root','legacy_domain_root','home_public_root','discovered_other_within_domain')
        crontab_command = @('present','absent')
        crontab_list_state = @('not_attempted','available','inaccessible_or_empty_account')
        env_state = @('absent','regular_file','symlink')
    }
    foreach ($key in $allowed.Keys) {
        if ([string] $Evidence[$key] -notin $allowed[$key]) {
            Stop-G1 'REMOTE_EVIDENCE_ENUM_REJECTED'
        }
    }
    if ([string] $Evidence.env_mode -notmatch '^(none|unknown|[0-7]{3,4})$' -or
        [string] $Evidence.backup_mode -notmatch '^(none|unknown|[0-7]{3,4})$' -or
        [int] $Evidence.critical_file_total -ne 3 -or
        [int] $Evidence.critical_file_candidate_match_count -gt 3 -or
        [int] $Evidence.critical_file_missing_count -gt 3) {
        Stop-G1 'REMOTE_EVIDENCE_CROSS_FIELD_REJECTED'
    }
    if (($Evidence.marker_state -eq 'valid_sha40' -and [string] $Evidence.marker_sha -notmatch '^[0-9a-f]{40}$') -or
        ($Evidence.marker_state -ne 'valid_sha40' -and $Evidence.marker_sha -ne 'none') -or
        ($Evidence.release_manifest_state -eq 'valid_sha40' -and [string] $Evidence.release_manifest_sha -notmatch '^[0-9a-f]{40}$') -or
        ($Evidence.release_manifest_state -ne 'valid_sha40' -and $Evidence.release_manifest_sha -ne 'none') -or
        ($Evidence.git_head_state -eq 'valid_sha40' -and [string] $Evidence.git_head_sha -notmatch '^[0-9a-f]{40}$') -or
        ($Evidence.git_head_state -ne 'valid_sha40' -and $Evidence.git_head_sha -ne 'none')) {
        Stop-G1 'REMOTE_EVIDENCE_IDENTITY_REJECTED'
    }
}

function Assert-HelperContract {
    $source = Get-Content -Raw -LiteralPath $PSCommandPath
    foreach ($required in @(
        'BatchMode=yes','StrictHostKeyChecking=yes','NumberOfPasswordPrompts=0','ConnectionAttempts=1',
        'ClearAllForwardings=yes','none_read_only_g1_inspection','retry_performed=false',
        'R0_EVIDENCE_HASH_MISMATCH','STEP_RETRY_FORBIDDEN'
    )) {
        if (-not $source.Contains($required)) { Stop-G1 'HELPER_CONTRACT_INCOMPLETE' }
    }
    $forbiddenOperations = @(
        ('mk' + 'dir '), ('to' + 'uch '), ('ch' + 'mod '), ('ch' + 'own '),
        ('r' + 'm -'), ('m' + 'v '), ('c' + 'p '), ('s' + 'cp.exe'), ('sf' + 'tp'),
        ('migrate ' + '--force'), ('git ' + 'push'), ('ln ' + '-s'), ('tar ' + '-x')
    )
    foreach ($forbidden in $forbiddenOperations) {
        if ($source.Contains($forbidden)) { Stop-G1 'HELPER_REMOTE_MUTATION_PRESENT' }
    }
    $envValueReadPatterns = @(
        ('Get-Content -Raw -LiteralPath "$APP/' + '.env"'),
        ('c' + 'at "$APP/' + '.env"'),
        ('so' + 'urce "$APP/' + '.env"')
    )
    if (@($envValueReadPatterns | Where-Object { $source.Contains($_) }).Count -gt 0) {
        Stop-G1 'HELPER_ENV_VALUE_READ_PRESENT'
    }
}

function Invoke-HelperSelfTest {
    $sample = @'
g1_remote_evidence=PASS
marker_state=valid_sha40
marker_sha=a06975797519c0fd4382258a9b4e0b92ef4fe8d5
release_manifest_state=absent
release_manifest_sha=none
git_head_state=unavailable
git_head_sha=none
marker_application_binding=unknown
critical_file_total=3
critical_file_candidate_match_count=0
critical_file_missing_count=0
application_fingerprint_sha256=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
backup_match_count=1
backup_state=readable_directory
backup_canonical_class=legacy_public_parent
backup_path_sha256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
backup_mode=705
backup_file_count=1
backup_total_bytes=1
backup_db_candidate_count=0
backup_archive_count=1
backup_newest_epoch=1
backup_oldest_epoch=1
backup_inventory_truncated=false
crontab_command=present
crontab_list_state=available
crontab_exit_code=0
crontab_entry_count=1
crontab_schedule_count=1
crontab_legacy_app_match_count=1
scheduler_process_count=0
queue_process_count=0
env_state=regular_file
env_mode=604
env_owner_matches_application=true
env_group_matches_application=true
production_change_scope=none_read_only_g1_inspection
secret_output=false
'@
    $evidence = ConvertFrom-FixedOutput -Value $sample
    Assert-RemoteEvidence -Evidence $evidence

    $remote = Get-RemoteInspectionScript
    $remoteForbiddenOperations = @(
        ('mk' + 'dir '), ('to' + 'uch '), ('ch' + 'mod '), ('ch' + 'own '),
        ('r' + 'm -'), ('m' + 'v '), ('c' + 'p '), ('s' + 'c' + 'p '), ('sf' + 'tp '),
        ('tar ' + '-x'), ('my' + 'sql '), ('ps' + 'ql ')
    )
    foreach ($forbidden in $remoteForbiddenOperations) {
        if ($remote.Contains($forbidden)) { Stop-G1 'SELF_TEST_REMOTE_MUTATION_PRESENT' }
    }
}

$repositoryRoot = $null
$evidenceRoot = $null
$statePath = $null
$state = $null
try {
    $repositoryRoot = Get-RepositoryRoot
    $evidenceRoot = Join-Path $repositoryRoot ('storage\app\release-audit\production-g1-gap-closure-' + $script:Candidate)
    $statePath = Join-Path $evidenceRoot 'execution-state.json'
    $helperSha256 = Get-HelperSha256

    $script:FailureStage = 'HELPER_INTEGRITY'
    Assert-HelperContract
    if ($VerifyOnly) {
        Invoke-HelperSelfTest
        Write-Output 'G1_HELPER_VERIFY=PASS'
        Write-Output ('candidate=' + $script:Candidate)
        Write-Output ('helper_sha256=' + $helperSha256)
        Write-Output 'r0_evidence_binding_verified=true'
        Write-Output 'remote_mutation_guard_verified=true'
        Write-Output 'secret_output_guard_verified=true'
        Write-Output 'retry_guard_verified=true'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }

    $preconditions = Get-LocalPreconditions -RepositoryRoot $repositoryRoot
    if ($VerifyLocalPreconditionsOnly) {
        Write-Output 'G1_LOCAL_PRECONDITIONS=PASS'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_change=false'
        exit 0
    }

    $script:FailureStage = 'ATTEMPT_GUARD'
    if (Test-Path -LiteralPath $statePath) {
        Stop-G1 'STEP_RETRY_FORBIDDEN'
    }
    if (-not (Test-Path -LiteralPath $evidenceRoot -PathType Container)) {
        New-Item -ItemType Directory -Path $evidenceRoot | Out-Null
    }
    $state = [pscustomobject]@{
        schema_version = 1
        candidate = $script:Candidate
        bundle_id = $script:BundleId
        status = 'ATTEMPT_STARTED'
        helper_sha256 = $helperSha256
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
    }
    Write-JsonAtomically -Path $statePath -Value $state
    $script:AttemptStarted = $true

    $script:FailureStage = 'PRODUCTION_READ_ONLY_G1_INSPECTION'
    $script:ProductionConnectionAttempted = $true
    $state.production_connection_attempted = $true
    Write-JsonAtomically -Path $statePath -Value $state
    $sshArgs = @(
        '-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ClearAllForwardings=yes','-o','ForwardAgent=no','-o','PermitLocalCommand=no',
        '-o',('UserKnownHostsFile=' + $preconditions.KnownHostsPath),$script:SshAlias,'bash -s'
    )
    $remoteResult = Invoke-CapturedProcess -FilePath $preconditions.SshPath -Arguments $sshArgs -StandardInput (Get-RemoteInspectionScript)
    if ($remoteResult.ExitCode -ne 0 -or -not [string]::IsNullOrEmpty($remoteResult.Stderr)) {
        $state.remote_exit_code = $remoteResult.ExitCode
        $state.stderr_sha256 = if ([string]::IsNullOrEmpty($remoteResult.Stderr)) { $null } else { Get-Sha256Text $remoteResult.Stderr }
        $state.stderr_bytes = if ([string]::IsNullOrEmpty($remoteResult.Stderr)) { 0 } else { [Text.Encoding]::UTF8.GetByteCount($remoteResult.Stderr) }
        Write-JsonAtomically -Path $statePath -Value $state
        Stop-G1 'G1_REMOTE_INSPECTION_FAILED'
    }
    $evidence = ConvertFrom-FixedOutput -Value $remoteResult.Stdout
    Assert-RemoteEvidence -Evidence $evidence

    $evidenceObject = [pscustomobject]@{
        schema_version = 1
        candidate = $script:Candidate
        bundle_id = $script:BundleId
        status = 'PASS'
        evidence_completeness = 'COMPLETE_FOR_SUPPORTED_G1_READ_ONLY_SCOPE'
        production_change_scope = 'none_read_only_g1_inspection'
        secret_output = $false
        raw_stdout_stored = $false
        raw_stderr_stored = $false
        collected_at_jst = Get-JstTimestamp
        result = [pscustomobject] $evidence
    }
    Write-JsonAtomically -Path (Join-Path $evidenceRoot 'g1-evidence.json') -Value $evidenceObject
    $state.status = 'PASS'
    $state.completed_at_jst = Get-JstTimestamp
    $state.remote_exit_code = 0
    Write-JsonAtomically -Path $statePath -Value $state

    Write-Output 'G1_EVIDENCE_GAP_CLOSURE=PASS'
    Write-Output 'production_change_scope=none_read_only_g1_inspection'
    Write-Output 'retry_available=false'
    Write-Output 'secret_output=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 0
}
catch {
    $safeErrorCode = if ($_.Exception.Message -match '^[A-Z0-9_]+$') { $_.Exception.Message } else { 'UNEXPECTED_LOCAL_FAILURE' }
    if ($script:AttemptStarted -and $null -ne $state) {
        $state.status = 'STOP'
        $state.completed_at_jst = Get-JstTimestamp
        $state.failure_stage = $script:FailureStage
        $state.safe_error_code = $safeErrorCode
        $state.production_connection_attempted = $script:ProductionConnectionAttempted
        $state.production_mutation = $false
        if ([string]::IsNullOrWhiteSpace([string] $state.stderr_sha256)) {
            $state.stderr_sha256 = Get-Sha256Text $_.Exception.Message
            $state.stderr_bytes = [Text.Encoding]::UTF8.GetByteCount($_.Exception.Message)
        }
        Write-JsonAtomically -Path $statePath -Value $state
    }
    elseif ($null -ne $repositoryRoot) {
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
        Write-JsonAtomically -Path (Join-Path $repositoryRoot 'storage\app\release-audit\g1-helper-preflight-failure.json') -Value $receipt
    }
    Write-G1Stop -SafeErrorCode $safeErrorCode
    exit 1
}
