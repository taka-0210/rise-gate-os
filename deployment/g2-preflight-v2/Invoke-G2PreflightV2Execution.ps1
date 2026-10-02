[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [string] $EvidenceFixture
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$script:ArtifactSha256 = 'b055585e09d7ae00c65bcaacad213fc4c92d260d4f8e2125884fe72d3cfb6b99'
$script:ManifestSha256 = 'f0abf01a18ae1e2bd0b2fb79197eb42c8570e62794da570453f30e603cb89dfc'
$script:ChecksumsSha256 = '64e6a07a72c8196f52c99bb965f837b1de9199e4528b98b87fe910b2d2043043'
$script:LauncherSha256 = '4586bdb8d903b1889f80ebc49dc88af7d876a4fae0d829018f4b1c80ed7de2ee'
$script:AuditorSha256 = '2a60d8c0b104915de400f5dad56a99b749cfe0be4d990b6b4da6c31024d25148'
$script:IsolatedEvidenceSha256 = 'ebebba036e01ae72333f4d446ae337d1ff89721ceac794a6bed435f4795edc86'
$script:R0StateSha256 = '159392dde20356febf20ea744953c7b901696e5c0f7dc5f45d1e2ae914f13b18'
$script:PlacementStateSha256 = '6b9af42c593ad52697cf3223cf3f644aec22f77321f7a1416c2890f9fc90a3b4'
$script:SshAlias = 'company-os-production'
$script:IdentityFile = 'codex-company-os-production'
$script:FailureStage = 'BOOTSTRAP'
$script:ProductionConnectionAttempted = $false
$script:RemoteExitCode = $null
$script:Stdout = ''
$script:Stderr = ''
$script:State = $null
$script:StatePath = $null
$script:EvidencePath = $null

function Get-JstTimestamp {
    return [DateTimeOffset]::UtcNow.ToOffset([TimeSpan]::FromHours(9)).ToString('o')
}

function Get-Sha256Text([AllowEmptyString()][string] $Value) {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value)))).Replace('-', '').ToLowerInvariant()
    }
    finally { $algorithm.Dispose() }
}

function Stop-Execution([Parameter(Mandatory = $true)][string] $Code) {
    throw [InvalidOperationException]::new($Code)
}

function Save-State {
    if ($null -eq $script:State -or [string]::IsNullOrWhiteSpace($script:StatePath)) { return }
    $json = $script:State | ConvertTo-Json -Depth 12
    [IO.File]::WriteAllText($script:StatePath, $json+[Environment]::NewLine, [Text.UTF8Encoding]::new($false))
}

function Complete-State([string] $Status, [string] $Code) {
    if ($null -eq $script:State) { return }
    $script:State.status = $Status
    $script:State.completed_at_jst = Get-JstTimestamp
    $script:State.failure_stage = $script:FailureStage
    $script:State.safe_error_code = if ($Status -eq 'STOP') { $Code } else { $null }
    $script:State.remote_exit_code = $script:RemoteExitCode
    $script:State.stdout_sha256 = if ([string]::IsNullOrEmpty($script:Stdout)) { $null } else { Get-Sha256Text $script:Stdout }
    $script:State.stdout_bytes = [Text.Encoding]::UTF8.GetByteCount($script:Stdout)
    $script:State.stderr_sha256 = if ([string]::IsNullOrEmpty($script:Stderr)) { $null } else { Get-Sha256Text $script:Stderr }
    $script:State.stderr_bytes = [Text.Encoding]::UTF8.GetByteCount($script:Stderr)
    $script:State.production_connection_attempted = $script:ProductionConnectionAttempted
    Save-State
}

function Assert-NativeArguments([Parameter(Mandatory = $true)][string[]] $Arguments) {
    foreach ($argument in $Arguments) {
        if ([string]::IsNullOrWhiteSpace($argument) -or $argument -match '\s') {
            Stop-Execution 'NATIVE_ARGUMENT_REJECTED'
        }
    }
}

function Invoke-Utf8CapturedProcess {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [Parameter(Mandatory = $true)][string[]] $Arguments
    )
    Assert-NativeArguments $Arguments
    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $FilePath
    $startInfo.Arguments = $Arguments -join ' '
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    try {
        if (-not $process.Start()) { Stop-Execution 'NATIVE_PROCESS_START_FAILED' }
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
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Stop-Execution $Code }
    if ((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Expected) { Stop-Execution $Code }
}

function Get-CommonArguments {
    return @(
        '-n','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ConnectTimeout=10','-o','ClearAllForwardings=yes',
        '-o','ForwardAgent=no','-o','PermitLocalCommand=no','-o','LogLevel=ERROR'
    )
}

function Get-RemoteArguments {
    $launcher = '.ir1-r0-audit/'+$script:Candidate+'/g2-preflight-v2/package/launcher.sh'
    $bundle = '.ir1-r0-audit/'+$script:Candidate+'/bundle'
    $environment = 'rise-gate.com/rise-gate-os/.env'
    return @((Get-CommonArguments)+@('-T',$script:SshAlias,'sh',$launcher,$bundle,$environment))
}

function Assert-SelfContract {
    $arguments = Get-RemoteArguments
    Assert-NativeArguments $arguments
    if (@($arguments | Where-Object { $_ -eq $script:SshAlias }).Count -ne 1 -or
        @($arguments | Where-Object { $_ -eq 'sh' }).Count -ne 1 -or
        @($arguments | Where-Object { $_ -eq '-T' }).Count -ne 1) {
        Stop-Execution 'REMOTE_INVOCATION_CONTRACT_MISMATCH'
    }
    $joined = $arguments -join ' '
    foreach ($required in @($script:Candidate, 'g2-preflight-v2/package/launcher.sh', 'rise-gate.com/rise-gate-os/.env')) {
        if (-not $joined.Contains($required)) { Stop-Execution 'REMOTE_INVOCATION_CONTRACT_INCOMPLETE' }
    }
    foreach ($forbidden in @('scp','sftp','mkdir','rm ','rmdir','mv ','ln ','touch','chmod','chown','artisan','migrate','mysql','mariadb','bash -s','sh -s')) {
        if ($joined.Contains($forbidden)) { Stop-Execution 'REMOTE_MUTATION_SCOPE_EXPANDED' }
    }
}

function Assert-ExactPropertySet([object] $Object, [string[]] $Expected, [string] $Code) {
    if ($null -eq $Object) { Stop-Execution $Code }
    $actual = @($Object.PSObject.Properties.Name | Sort-Object)
    $expectedSorted = @($Expected | Sort-Object)
    if (($actual -join "`n") -ne ($expectedSorted -join "`n")) { Stop-Execution $Code }
}

function Assert-SanitizedValue([object] $Value, [int] $Depth = 0) {
    if ($Depth -gt 12) { Stop-Execution 'EVIDENCE_DEPTH_EXCEEDED' }
    if ($null -eq $Value -or $Value -is [bool] -or $Value -is [byte] -or $Value -is [int16] -or
        $Value -is [int32] -or $Value -is [int64] -or $Value -is [uint16] -or $Value -is [uint32] -or
        $Value -is [uint64] -or $Value -is [single] -or $Value -is [double] -or $Value -is [decimal]) { return }
    if ($Value -is [string]) {
        if ($Value.Length -gt 512 -or $Value -match "[`r`n`0]" -or
            $Value -match '(?i)(-----BEGIN|password\s*=|db_password|api[_-]?key|credential\s*=|/home/|[A-Za-z]:\\|\.env)') {
            Stop-Execution 'EVIDENCE_SANITIZATION_REJECTED'
        }
        return
    }
    if ($Value -is [Collections.IDictionary]) {
        if ($Value.Count -gt 64) { Stop-Execution 'EVIDENCE_COLLECTION_LIMIT_EXCEEDED' }
        foreach ($key in $Value.Keys) {
            if (($key -as [string]) -notmatch '^[A-Za-z0-9_]+$') { Stop-Execution 'EVIDENCE_PROPERTY_REJECTED' }
            Assert-SanitizedValue $Value[$key] ($Depth+1)
        }
        return
    }
    if ($Value -is [Collections.IEnumerable]) {
        $items = @($Value)
        if ($items.Count -gt 64) { Stop-Execution 'EVIDENCE_COLLECTION_LIMIT_EXCEEDED' }
        foreach ($item in $items) { Assert-SanitizedValue $item ($Depth+1) }
        return
    }
    $properties = @($Value.PSObject.Properties)
    if ($properties.Count -gt 64) { Stop-Execution 'EVIDENCE_COLLECTION_LIMIT_EXCEEDED' }
    foreach ($property in $properties) {
        if ($property.Name -notmatch '^[A-Za-z0-9_]+$') { Stop-Execution 'EVIDENCE_PROPERTY_REJECTED' }
        Assert-SanitizedValue $property.Value ($Depth+1)
    }
}

function Get-PropertyValue([object] $Object, [string] $Name, [object] $Default = $null) {
    if ($null -eq $Object) { return $Default }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $Default }
    return $property.Value
}

function Assert-FrameDataContract([object] $Frame) {
    $signature = $Frame.layer+'/'+$Frame.event+'/'+$Frame.status
    switch ($signature) {
        'shell/shell_started/PASS' {
            Assert-ExactPropertySet $Frame.data @('candidate') 'SHELL_STARTED_DATA_MISMATCH'
        }
        'shell/artifact_verified/PASS' {
            Assert-ExactPropertySet $Frame.data @('manifest_schema','hashes_verified') 'ARTIFACT_FRAME_DATA_MISMATCH'
        }
        'shell/php_discovered/PASS' {
            Assert-ExactPropertySet $Frame.data @('php_cli') 'PHP_DISCOVERY_DATA_MISMATCH'
        }
        'php/contract_started/PASS' {
            Assert-ExactPropertySet $Frame.data @('candidate','application_bootstrap','database_connection') 'PHP_CONTRACT_DATA_MISMATCH'
        }
        'database/check_completed/PASS' {
            Assert-ExactPropertySet $Frame.data @('check','database_connection','sql_total_statements','sql_rejected_statements') 'CHECK_FRAME_DATA_MISMATCH'
        }
        'shell/shell_terminal/PASS' {
            Assert-ExactPropertySet $Frame.data @('php_exit_code','production_change_scope') 'SHELL_TERMINAL_DATA_MISMATCH'
        }
        'shell/terminal/STOP' {
            Assert-ExactPropertySet $Frame.data @('safe_error_code','failure_stage','production_change_scope','secret_output') 'SHELL_STOP_DATA_MISMATCH'
        }
        'php/terminal/STOP' {
            Assert-ExactPropertySet $Frame.data @(
                'safe_error_code','failure_stage','evidence_completeness','database_connection','last_completed_check',
                'completed_checks','sql_safety','production_change_scope','secret_output','raw_identifier_output','raw_exception_output'
            ) 'PHP_STOP_DATA_MISMATCH'
            Assert-SqlSafety (Get-PropertyValue $Frame.data 'sql_safety')
        }
        'php/terminal/PASS' {
            Assert-ExactPropertySet $Frame.data @(
                'candidate','evidence_completeness','database','affected_table_metrics','organization_users',
                'collision_preflight','activity_snapshot','sql_safety','capabilities','production_change_scope',
                'secret_output','raw_identifier_output','raw_exception_output'
            ) 'PHP_PASS_DATA_MISMATCH'
            $database = Get-PropertyValue $Frame.data 'database'
            Assert-ExactPropertySet $database @('engine_family','version','character_set','collation') 'DATABASE_EVIDENCE_SCHEMA_MISMATCH'
            $metrics = @(Get-PropertyValue $Frame.data 'affected_table_metrics')
            $allowedTables = @('ai_proposals','ai_proposal_items','organizations','organization_users','users','business_domains','projects','project_members','roadmaps','improvements','tasks')
            $seenTables = @{}
            foreach ($metric in $metrics) {
                Assert-ExactPropertySet $metric @('table_name','approximate_rows','data_bytes','index_bytes') 'TABLE_METRIC_SCHEMA_MISMATCH'
                $table = [string](Get-PropertyValue $metric 'table_name')
                if ($table -notin $allowedTables -or $seenTables.ContainsKey($table) -or
                    [int64](Get-PropertyValue $metric 'approximate_rows' -1) -lt 0 -or
                    [int64](Get-PropertyValue $metric 'data_bytes' -1) -lt 0 -or
                    [int64](Get-PropertyValue $metric 'index_bytes' -1) -lt 0) {
                    Stop-Execution 'TABLE_METRIC_CONTRACT_MISMATCH'
                }
                $seenTables[$table] = $true
            }
            $organizationUsers = Get-PropertyValue $Frame.data 'organization_users'
            Assert-ExactPropertySet $organizationUsers @('exact_row_count','legacy_role_counts') 'ORGANIZATION_USERS_SCHEMA_MISMATCH'
            if ([int64](Get-PropertyValue $organizationUsers 'exact_row_count' -1) -lt 0) { Stop-Execution 'ORGANIZATION_USERS_COUNT_REJECTED' }
            $roles = Get-PropertyValue $organizationUsers 'legacy_role_counts'
            Assert-ExactPropertySet $roles @('owner','admin','member','viewer','other_or_null') 'ROLE_COUNTS_SCHEMA_MISMATCH'
            foreach ($role in @('owner','admin','member','viewer','other_or_null')) {
                if ([int64](Get-PropertyValue $roles $role -1) -lt 0) { Stop-Execution 'ROLE_COUNT_REJECTED' }
            }
            $collisions = Get-PropertyValue $Frame.data 'collision_preflight'
            Assert-ExactPropertySet $collisions @('existing_expected_new_column_count','existing_expected_new_table_count') 'COLLISION_SCHEMA_MISMATCH'
            if ([int64](Get-PropertyValue $collisions 'existing_expected_new_column_count' -1) -lt 0 -or
                [int64](Get-PropertyValue $collisions 'existing_expected_new_table_count' -1) -lt 0) {
                Stop-Execution 'COLLISION_COUNT_REJECTED'
            }
            $activity = Get-PropertyValue $Frame.data 'activity_snapshot'
            Assert-ExactPropertySet $activity @('active_transactions','pending_metadata_locks','snapshot_only') 'ACTIVITY_SCHEMA_MISMATCH'
            foreach ($name in @('active_transactions','pending_metadata_locks')) {
                $snapshot = Get-PropertyValue $activity $name
                $countName = if ($name -eq 'active_transactions') { 'active_count' } else { 'pending_count' }
                Assert-ExactPropertySet $snapshot @('status',$countName) 'ACTIVITY_ITEM_SCHEMA_MISMATCH'
                $status = [string](Get-PropertyValue $snapshot 'status')
                $count = Get-PropertyValue $snapshot $countName
                if ($status -notin @('SUPPORTED','UNSUPPORTED') -or
                    ($status -eq 'SUPPORTED' -and ($null -eq $count -or [int64]$count -lt 0)) -or
                    ($status -eq 'UNSUPPORTED' -and $null -ne $count)) {
                    Stop-Execution 'ACTIVITY_ITEM_CONTRACT_MISMATCH'
                }
            }
            if ((Get-PropertyValue $activity 'snapshot_only') -ne $true) { Stop-Execution 'ACTIVITY_SNAPSHOT_CONTRACT_MISMATCH' }
            $capabilities = Get-PropertyValue $Frame.data 'capabilities'
            Assert-ExactPropertySet $capabilities @('migration_runtime_prediction','external_writer_full_visibility') 'CAPABILITY_SCHEMA_MISMATCH'
            Assert-SqlSafety (Get-PropertyValue $Frame.data 'sql_safety')
        }
        default { Stop-Execution 'EVIDENCE_FRAME_COMBINATION_REJECTED' }
    }
}

function Assert-SqlSafety([object] $Sql) {
    Assert-ExactPropertySet $Sql @(
        'allowed_statement_classes','statement_counts','total_statements','rejected_statements',
        'statement_limit','persistent_db_write','ddl','migration_execution'
    ) 'SQL_SAFETY_SCHEMA_MISMATCH'
    $allowed = @(Get-PropertyValue $Sql 'allowed_statement_classes')
    $statementCounts = Get-PropertyValue $Sql 'statement_counts'
    Assert-ExactPropertySet $statementCounts @('SELECT') 'SQL_CLASS_SCHEMA_MISMATCH'
    $total = [int](Get-PropertyValue $Sql 'total_statements' -1)
    $rejected = [int](Get-PropertyValue $Sql 'rejected_statements' -1)
    $limit = [int](Get-PropertyValue $Sql 'statement_limit' -1)
    if (($allowed -join ',') -ne 'SELECT' -or $limit -ne 24 -or $total -lt 0 -or $total -gt 24 -or
        [int](Get-PropertyValue $statementCounts 'SELECT' -1) -ne $total -or $rejected -ne 0 -or
        (Get-PropertyValue $Sql 'persistent_db_write') -ne $false -or (Get-PropertyValue $Sql 'ddl') -ne $false -or
        (Get-PropertyValue $Sql 'migration_execution') -ne $false) {
        Stop-Execution 'SQL_SAFETY_CONTRACT_FAILED'
    }
}

function ConvertTo-V2Evidence([string] $Raw) {
    $bytes = [Text.Encoding]::UTF8.GetByteCount($Raw)
    if ($bytes -lt 2 -or $bytes -gt 131072) { Stop-Execution 'EVIDENCE_SIZE_REJECTED' }
    $lines = @($Raw -split "`r?`n" | Where-Object { $_ -ne '' })
    if ($lines.Count -lt 1 -or $lines.Count -gt 32) { Stop-Execution 'EVIDENCE_FRAME_COUNT_REJECTED' }
    $frames = @()
    foreach ($line in $lines) {
        if ([Text.Encoding]::UTF8.GetByteCount($line) -gt 32768) { Stop-Execution 'EVIDENCE_FRAME_SIZE_REJECTED' }
        try { $frame = $line | ConvertFrom-Json } catch { Stop-Execution 'EVIDENCE_JSON_INVALID' }
        Assert-ExactPropertySet $frame @('schema_version','sequence','layer','event','status','data') 'EVIDENCE_FRAME_SCHEMA_MISMATCH'
        if ([int]$frame.schema_version -ne 2 -or [int]$frame.sequence -lt 1 -or [int]$frame.sequence -gt 32 -or
            $frame.layer -notin @('shell','php','database') -or
            $frame.event -notin @('shell_started','artifact_verified','php_discovered','contract_started','check_completed','terminal','shell_terminal') -or
            $frame.status -notin @('PASS','STOP')) {
            Stop-Execution 'EVIDENCE_FRAME_CONTRACT_MISMATCH'
        }
        Assert-SanitizedValue $frame
        Assert-FrameDataContract $frame
        $frames += $frame
    }

    if ($frames[0].layer -ne 'shell' -or $frames[0].event -ne 'shell_started' -or $frames[0].status -ne 'PASS' -or
        (Get-PropertyValue $frames[0].data 'candidate') -ne $script:Candidate) {
        Stop-Execution 'EVIDENCE_IDENTITY_MISMATCH'
    }

    $stopFrames = @($frames | Where-Object { $_.status -eq 'STOP' })
    if ($stopFrames.Count -gt 0) {
        $detailedStops = @($stopFrames | Where-Object { $null -ne (Get-PropertyValue $_.data 'sql_safety') })
        $last = if ($detailedStops.Count -gt 0) { $detailedStops[-1] } else { $stopFrames[-1] }
        $code = [string](Get-PropertyValue $last.data 'safe_error_code' 'G2_V2_REMOTE_EXECUTION_STOPPED')
        $stage = [string](Get-PropertyValue $last.data 'failure_stage' 'remote_execution')
        if ($code -notmatch '^G2_V2_[A-Z0-9_]+$' -or $stage -notmatch '^[a-z0-9_]+$') { Stop-Execution 'EVIDENCE_STOP_CONTRACT_MISMATCH' }
        $sql = Get-PropertyValue $last.data 'sql_safety'
        if ($null -ne $sql) { Assert-SqlSafety $sql }
        $checks = @($frames | Where-Object { $_.layer -eq 'database' -and $_.event -eq 'check_completed' })
        return [pscustomobject]@{
            Outcome='STOP'; Frames=$frames; SafeErrorCode=$code; FailureStage=$stage
            FrameCount=$frames.Count; CompletedCheckCount=$checks.Count
            DatabaseConnection=[string](Get-PropertyValue $last.data 'database_connection' 'unknown')
            SqlTotal=if ($null -eq $sql) { $null } else { [int](Get-PropertyValue $sql 'total_statements' 0) }
            SqlRejected=if ($null -eq $sql) { $null } else { [int](Get-PropertyValue $sql 'rejected_statements' 0) }
        }
    }

    $expected = @(
        'shell/shell_started/PASS','shell/artifact_verified/PASS','shell/php_discovered/PASS','php/contract_started/PASS',
        'database/check_completed/PASS','database/check_completed/PASS','database/check_completed/PASS',
        'database/check_completed/PASS','database/check_completed/PASS','database/check_completed/PASS',
        'database/check_completed/PASS','database/check_completed/PASS','database/check_completed/PASS',
        'database/check_completed/PASS','php/terminal/PASS','shell/shell_terminal/PASS'
    )
    $actual = @($frames | ForEach-Object { $_.layer+'/'+$_.event+'/'+$_.status })
    if (($actual -join "`n") -ne ($expected -join "`n")) { Stop-Execution 'EVIDENCE_PASS_SEQUENCE_MISMATCH' }

    $expectedChecks = @(
        'environment_loaded','database_connection','database_identity','affected_table_metrics','organization_users_row_count',
        'legacy_role_distribution','column_collision_preflight','table_collision_preflight','active_transaction_snapshot','metadata_lock_snapshot'
    )
    $checkFrames = @($frames | Where-Object { $_.layer -eq 'database' -and $_.event -eq 'check_completed' })
    $actualChecks = @($checkFrames | ForEach-Object { [string](Get-PropertyValue $_.data 'check') })
    if (($actualChecks -join "`n") -ne ($expectedChecks -join "`n")) { Stop-Execution 'EVIDENCE_CHECK_SEQUENCE_MISMATCH' }
    foreach ($check in $checkFrames) {
        if ((Get-PropertyValue $check.data 'database_connection') -notin @('not_attempted','established') -or
            [int](Get-PropertyValue $check.data 'sql_total_statements' -1) -lt 0 -or
            [int](Get-PropertyValue $check.data 'sql_total_statements' -1) -gt 24 -or
            [int](Get-PropertyValue $check.data 'sql_rejected_statements' -1) -ne 0) {
            Stop-Execution 'EVIDENCE_CHECK_CONTRACT_MISMATCH'
        }
    }

    $contract = $frames[3]
    if ((Get-PropertyValue $contract.data 'candidate') -ne $script:Candidate -or
        (Get-PropertyValue $contract.data 'application_bootstrap') -ne 'not_used' -or
        (Get-PropertyValue $contract.data 'database_connection') -ne 'not_attempted') {
        Stop-Execution 'PHP_CONTRACT_MISMATCH'
    }
    $terminal = $frames[14]
    if ((Get-PropertyValue $terminal.data 'candidate') -ne $script:Candidate -or
        (Get-PropertyValue $terminal.data 'evidence_completeness') -ne 'complete_for_supported_scope' -or
        (Get-PropertyValue $terminal.data 'production_change_scope') -ne 'none_read_only_g2_preflight_v2' -or
        (Get-PropertyValue $terminal.data 'secret_output') -ne $false -or
        (Get-PropertyValue $terminal.data 'raw_identifier_output') -ne $false -or
        (Get-PropertyValue $terminal.data 'raw_exception_output') -ne $false) {
        Stop-Execution 'PHP_TERMINAL_CONTRACT_MISMATCH'
    }
    $sql = Get-PropertyValue $terminal.data 'sql_safety'
    Assert-SqlSafety $sql
    if ([int](Get-PropertyValue $sql 'total_statements' -1) -ne 8) { Stop-Execution 'SQL_EXPECTED_COUNT_MISMATCH' }
    $shellTerminal = $frames[15]
    if ([int](Get-PropertyValue $shellTerminal.data 'php_exit_code' -1) -ne 0 -or
        (Get-PropertyValue $shellTerminal.data 'production_change_scope') -ne 'none_read_only_g2_preflight_v2') {
        Stop-Execution 'SHELL_TERMINAL_CONTRACT_MISMATCH'
    }
    return [pscustomobject]@{
        Outcome='PASS'; Frames=$frames; SafeErrorCode=$null; FailureStage=$null
        FrameCount=$frames.Count; CompletedCheckCount=$checkFrames.Count; DatabaseConnection='established'
        SqlTotal=[int](Get-PropertyValue $sql 'total_statements' 0); SqlRejected=[int](Get-PropertyValue $sql 'rejected_statements' 0)
    }
}

function Write-SafeEvidence([object[]] $Frames) {
    $lines = @($Frames | ForEach-Object { $_ | ConvertTo-Json -Depth 12 -Compress })
    [IO.File]::WriteAllText($script:EvidencePath, ($lines -join "`n")+"`n", [Text.UTF8Encoding]::new($false))
}

function Get-LocalPreconditions([string] $Root) {
    $script:FailureStage = 'LOCAL_EXACT_BINDING'
    $artifact = Join-Path $Root ('storage\app\release-audit\ir1-g2-preflight-v2-'+$script:Candidate+'.tar.gz')
    Assert-FileHash $artifact $script:ArtifactSha256 'ARTIFACT_IDENTITY_MISMATCH'
    Assert-FileHash ($artifact+'.manifest.json') $script:ManifestSha256 'MANIFEST_IDENTITY_MISMATCH'
    Assert-FileHash ($artifact+'.checksums.sha256') $script:ChecksumsSha256 'CHECKSUMS_IDENTITY_MISMATCH'
    Assert-FileHash (Join-Path $Root 'deployment\g2-preflight-v2\launcher.sh') $script:LauncherSha256 'LAUNCHER_IDENTITY_MISMATCH'
    Assert-FileHash (Join-Path $Root 'deployment\g2-preflight-v2\auditor.php') $script:AuditorSha256 'AUDITOR_IDENTITY_MISMATCH'
    Assert-FileHash (Join-Path $Root 'storage\app\release-audit\g2-preflight-v2-isolated-verification.json') $script:IsolatedEvidenceSha256 'ISOLATED_EVIDENCE_MISMATCH'

    $r0StatePath = Join-Path $Root ('storage\app\release-audit\production-r0-human-'+$script:Candidate+'-corrective-2\execution-state.json')
    Assert-FileHash $r0StatePath $script:R0StateSha256 'R0_STATE_IDENTITY_MISMATCH'
    try { $r0State = Get-Content -Raw -LiteralPath $r0StatePath | ConvertFrom-Json } catch { Stop-Execution 'R0_STATE_INVALID' }
    if ($r0State.candidate -ne $script:Candidate -or $r0State.last_step -ne 6 -or $r0State.last_status -ne 'PASS') {
        Stop-Execution 'R0_COMPLETION_CONTRACT_MISMATCH'
    }

    $placementStatePath = Join-Path $Root ('storage\app\release-audit\production-g2-preflight-v2-placement-'+$script:Candidate+'\execution-state.json')
    Assert-FileHash $placementStatePath $script:PlacementStateSha256 'PLACEMENT_STATE_IDENTITY_MISMATCH'
    try { $placement = Get-Content -Raw -LiteralPath $placementStatePath | ConvertFrom-Json } catch { Stop-Execution 'PLACEMENT_STATE_INVALID' }
    if ($placement.candidate -ne $script:Candidate -or $placement.artifact_sha256 -ne $script:ArtifactSha256 -or
        $placement.status -ne 'PASS' -or $placement.remote_exit_code -ne 0 -or $placement.overwrite_performed -ne $false -or
        $placement.db_connection_attempted -ne $false -or $placement.sql_executed -ne $false -or
        $placement.migration_executed -ne $false -or $placement.secret_output -ne $false) {
        Stop-Execution 'PLACEMENT_COMPLETION_CONTRACT_MISMATCH'
    }

    $script:FailureStage = 'LOCAL_OPENSSH_CONTRACT'
    $ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue
    $sshKeygen = Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $sshKeygen) { Stop-Execution 'OPENSSH_CLIENT_UNAVAILABLE' }
    $configResult = Invoke-Utf8CapturedProcess $ssh.Source @('-G',$script:SshAlias)
    if ($configResult.ExitCode -ne 0) { Stop-Execution 'SSH_CONFIG_UNAVAILABLE' }
    $config = @{}
    foreach ($line in ($configResult.Stdout -split "`r?`n")) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2) { $config[$parts[0].ToLowerInvariant()] = $parts[1] }
    }
    foreach ($required in @('hostname','user','port','identityfile')) {
        if (-not $config.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($config[$required])) { Stop-Execution 'SSH_CONFIG_INCOMPLETE' }
    }
    if ((Split-Path -Leaf $config.identityfile.Trim('"')) -ne $script:IdentityFile -or $config.identitiesonly -ne 'yes') {
        Stop-Execution 'SSH_IDENTITY_CONTRACT_MISMATCH'
    }
    foreach ($directive in @('remotecommand','localcommand','proxycommand','proxyjump')) {
        if ($config.ContainsKey($directive) -and $config[$directive] -ne 'none') { Stop-Execution 'SSH_AUTOMATIC_COMMAND_FORBIDDEN' }
    }
    $knownHosts = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\known_hosts'
    if (-not (Test-Path -LiteralPath $knownHosts -PathType Leaf)) { Stop-Execution 'KNOWN_HOSTS_MISSING' }
    $lookup = '['+$config.hostname+']:'+$config.port
    $known = Invoke-Utf8CapturedProcess $sshKeygen.Source @('-F',$lookup,'-f',$knownHosts)
    if ($known.ExitCode -ne 0) { Stop-Execution 'HOST_KEY_NOT_REGISTERED' }
    $types = @($known.Stdout -split "`r?`n" | Where-Object { $_ -and -not $_.StartsWith('#') } | ForEach-Object { ($_ -split '\s+')[1] } | Sort-Object -Unique)
    if (($types -join ',') -ne 'ecdsa-sha2-nistp256,ssh-ed25519,ssh-rsa') { Stop-Execution 'HOST_KEY_CONTRACT_MISMATCH' }
    return [pscustomobject]@{ SshPath=$ssh.Source }
}

try {
    Assert-SelfContract
    $root = Get-RepositoryRoot
    $preconditions = Get-LocalPreconditions $root
    $evidenceRoot = Join-Path $root ('storage\app\release-audit\production-g2-preflight-v2-execution-'+$script:Candidate)
    if (Test-Path -LiteralPath $evidenceRoot) { Stop-Execution 'EXECUTION_ATTEMPT_ALREADY_RECORDED' }

    if ($VerifyOnly) {
        $fixtureResult = $null
        if (-not [string]::IsNullOrWhiteSpace($EvidenceFixture)) {
            if (-not (Test-Path -LiteralPath $EvidenceFixture -PathType Leaf)) { Stop-Execution 'EVIDENCE_FIXTURE_MISSING' }
            $fixture = [IO.File]::ReadAllText([IO.Path]::GetFullPath($EvidenceFixture), [Text.Encoding]::UTF8)
            $fixtureResult = ConvertTo-V2Evidence $fixture
        }
        Write-Output 'G2_V2_EXECUTION_HELPER_VERIFY=PASS'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'production_mutation=false'
        if ($null -ne $fixtureResult) {
            Write-Output ('fixture_outcome='+$fixtureResult.Outcome)
            Write-Output ('fixture_database_connection='+$fixtureResult.DatabaseConnection)
            Write-Output ('fixture_sql_statement_count='+$(if ($null -eq $fixtureResult.SqlTotal) { 'unknown' } else { $fixtureResult.SqlTotal }))
            Write-Output ('fixture_rejected_statement_count='+$(if ($null -eq $fixtureResult.SqlRejected) { 'unknown' } else { $fixtureResult.SqlRejected }))
        }
        exit 0
    }
    if (-not [string]::IsNullOrWhiteSpace($EvidenceFixture)) { Stop-Execution 'EVIDENCE_FIXTURE_FORBIDDEN' }

    New-Item -ItemType Directory -Path $evidenceRoot | Out-Null
    $script:StatePath = Join-Path $evidenceRoot 'execution-state.json'
    $script:EvidencePath = Join-Path $evidenceRoot 'evidence.ndjson'
    $script:State = [pscustomobject]@{
        schema_version=1; candidate=$script:Candidate; artifact_sha256=$script:ArtifactSha256
        status='ATTEMPT_STARTED'; started_at_jst=Get-JstTimestamp; completed_at_jst=$null
        failure_stage='PRODUCTION_READ_ONLY_EXECUTION'; safe_error_code=$null; remote_exit_code=$null
        stdout_sha256=$null; stdout_bytes=0; stderr_sha256=$null; stderr_bytes=0
        production_connection_attempted=$false; ssh_attempt_limit=1; retry_performed=$false
        remote_file_mutation=false; persistent_db_write=false; ddl=false; migration_executed=false
        data_mutation=false; frame_count=0; completed_check_count=0; database_connection='unknown'
        sql_statement_class='SELECT'; sql_statement_limit=24; sql_statement_count=$null
        rejected_statement_count=$null; evidence_file_written=false; secret_output=false
    }
    Save-State

    $script:FailureStage = 'PRODUCTION_READ_ONLY_EXECUTION'
    $script:ProductionConnectionAttempted = $true
    $script:State.production_connection_attempted = $true
    Save-State
    $remote = Invoke-Utf8CapturedProcess $preconditions.SshPath (Get-RemoteArguments)
    $script:RemoteExitCode = $remote.ExitCode
    $script:Stdout = $remote.Stdout
    $script:Stderr = $remote.Stderr
    $parsed = ConvertTo-V2Evidence $script:Stdout
    $script:State.frame_count = $parsed.FrameCount
    $script:State.completed_check_count = $parsed.CompletedCheckCount
    $script:State.database_connection = $parsed.DatabaseConnection
    $script:State.sql_statement_count = $parsed.SqlTotal
    $script:State.rejected_statement_count = $parsed.SqlRejected
    Write-SafeEvidence $parsed.Frames
    $script:State.evidence_file_written = $true
    Save-State

    if (-not [string]::IsNullOrEmpty($script:Stderr)) { Stop-Execution 'G2_V2_REMOTE_STDERR_PRESENT' }
    if ($script:RemoteExitCode -ne 0) {
        $script:FailureStage = if ($parsed.FailureStage) { $parsed.FailureStage } else { 'remote_execution' }
        Stop-Execution $(if ($parsed.SafeErrorCode) { $parsed.SafeErrorCode } else { 'G2_V2_REMOTE_EXECUTION_FAILED' })
    }
    if ($parsed.Outcome -ne 'PASS') { Stop-Execution 'G2_V2_REMOTE_PASS_MISSING' }

    Complete-State 'PASS' $null
    Write-Output 'G2_V2_READ_ONLY_EXECUTION=PASS'
    Write-Output 'evidence_schema=2'
    Write-Output 'candidate_verified=true'
    Write-Output ('frame_count='+$parsed.FrameCount)
    Write-Output ('completed_check_count='+$parsed.CompletedCheckCount)
    Write-Output ('database_connection='+$parsed.DatabaseConnection)
    Write-Output ('sql_statement_count='+$parsed.SqlTotal)
    Write-Output 'sql_statement_class=SELECT'
    Write-Output ('rejected_statement_count='+$parsed.SqlRejected)
    Write-Output 'production_change_scope=none_read_only_g2_preflight_v2'
    Write-Output 'retry_available=false'
    Write-Output 'secret_output=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 0
}
catch {
    $code = if ($_.Exception.Message -match '^[A-Z0-9_]+$') { $_.Exception.Message } else { 'UNEXPECTED_LOCAL_FAILURE' }
    Complete-State 'STOP' $code
    $dbState = if ($null -eq $script:State) { 'not_attempted' } else { $script:State.database_connection }
    $sqlCount = if ($null -eq $script:State -or $null -eq $script:State.sql_statement_count) { 'unknown' } else { [string]$script:State.sql_statement_count }
    $rejected = if ($null -eq $script:State -or $null -eq $script:State.rejected_statement_count) { 'unknown' } else { [string]$script:State.rejected_statement_count }
    Write-Output 'G2_V2_READ_ONLY_EXECUTION=STOP'
    Write-Output ('safe_error_code='+$code)
    Write-Output ('failure_stage='+$script:FailureStage)
    Write-Output ('production_connection_attempted='+$script:ProductionConnectionAttempted.ToString().ToLowerInvariant())
    Write-Output ('database_connection='+$dbState)
    Write-Output ('sql_statement_count='+$sqlCount)
    Write-Output ('rejected_statement_count='+$rejected)
    Write-Output 'production_change_scope=none_read_only_g2_preflight_v2'
    Write-Output 'retry_performed=false'
    Write-Output 'secret_output=false'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}
