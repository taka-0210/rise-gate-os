[CmdletBinding()]
param(
    [switch] $VerifyOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$HelperGeneration = 'g5-shared-state-required-diagnostic-v1'
$ExpectedContractId = 'company-os.ir1.g5-shared-state-required-diagnostic.v1'
$ExpectedContractSha256 = 'b124b9f9688fdda86a089e80525bb608b955b7a30a2589c65d3c3303fa1a8f78'
$ExpectedDiagnosticSha256 = '6bf7e305f423d1a11a8604e8173dc712f82fdb28175ab12bfcce8932f98b19b2'
$ExpectedInitialStateSha256 = 'bb98d39709625d619d0365b57850613580237be837dcc85a863d00adfbcb248d'
$ExpectedCorrectiveStateSha256 = '403fdcbcc4b9d9cdae9e9d1ec99b07be48ce502ef9601ec7accaa31ec3a08331'
$ExpectedBaseContractSha256 = 'cc11ad5a67e0f3f869da15091d91740f9e4d584d90995bd86d38ae22d7a282d1'
$ExpectedCorrectiveContractSha256 = '1637b67d6acc95c50e2433d9e48cb21328522d357ae7468c0bb1b973fa443064'
$ExpectedAllowlistSha256 = 'd408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec'

$SourceHost = 'sv17033.xserver.jp'
$SourceUser = 'xs257823'
$SourcePort = '10022'
$SourceIdentityLeaf = 'codex-company-os-production'
$SourceIdentityFingerprint = 'SHA256:d/dPUPa6KjfXVOpG1Sqqp66GMFjYtxYUR1dqhaRBQBg'
$SourceHostKeyFingerprint = 'SHA256:lkUHlNS7K7nVe/slV97qC08nvzqNzsgUsVD9p2Q1KCs'
$SourceEnv = '/home/xs257823/rise-gate.com/rise-gate-os/.env'
$Confirmation = 'IR1-G5-SHARED-REQUIRED-DIAGNOSTIC'
$Utf8NoBom = [Text.UTF8Encoding]::new($false)

$ConnectionAttempted = $false
$SourceConnectionAttempted = $false
$TargetConnectionAttempted = $false
$RemoteProcessCount = 0
$State = $null
$StatePath = $null
$StateWriteGeneration = 0
$FailureStage = 'LOCAL_PRECONDITIONS'

function Stop-G5Diagnostic([string] $Code) {
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
    return ([IO.File]::ReadAllText($Path)).Replace("`r`n", "`n").Replace("`r", "`n")
}

function Save-Json([string] $Path, [object] $Value) {
    $json = $Value | ConvertTo-Json -Depth 12
    $temporaryPath = $Path + '.tmp'
    $backupPath = $Path + '.previous'
    if ([IO.File]::Exists($temporaryPath) -or [IO.File]::Exists($backupPath)) {
        Stop-G5Diagnostic 'STATE_PERSISTENCE_RESIDUAL_PRESENT'
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

function Assert-NativeArguments([string[]] $Arguments) {
    foreach ($argument in $Arguments) {
        if ([string]::IsNullOrWhiteSpace($argument) -or $argument -match '\s') {
            Stop-G5Diagnostic 'NATIVE_ARGUMENT_REJECTED'
        }
    }
}

function Invoke-Native(
    [string] $File,
    [string[]] $Arguments,
    [AllowNull()][string] $InputText,
    [bool] $IsProductionConnection
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
        if (-not $process.Start()) { Stop-G5Diagnostic 'NATIVE_PROCESS_START_FAILED' }
        if ($IsProductionConnection) {
            $script:ConnectionAttempted = $true
            $script:SourceConnectionAttempted = $true
            $script:RemoteProcessCount++
            if ($null -ne $script:State) {
                $script:State.production_connection_attempted = $true
                $script:State.source_connection_attempted = $true
                $script:State.remote_process_count = $script:RemoteProcessCount
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

function Assert-FileBinding([string] $Path, [string] $Hash, [string] $Code) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf) -or (Get-FileSha256 $Path) -ne $Hash) {
        Stop-G5Diagnostic $Code
    }
}

function Assert-KeyFingerprint([string] $Keygen, [string] $Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Stop-G5Diagnostic 'SSH_IDENTITY_MISSING' }
    $result = Invoke-Native $Keygen @('-lf',$Path) $null $false
    if ($result.ExitCode -ne 0 -or $result.Stdout -notmatch [regex]::Escape($SourceIdentityFingerprint)) {
        Stop-G5Diagnostic 'SSH_IDENTITY_FINGERPRINT_MISMATCH'
    }
}

function Assert-HostKey([string] $Keygen, [string] $KnownHostsPath) {
    if (-not (Test-Path -LiteralPath $KnownHostsPath -PathType Leaf)) { Stop-G5Diagnostic 'KNOWN_HOSTS_MISSING' }
    $lookup = "[$SourceHost]:$SourcePort"
    $found = Invoke-Native $Keygen @('-F',$lookup,'-f',$KnownHostsPath) $null $false
    if ($found.ExitCode -ne 0) { Stop-G5Diagnostic 'HOST_KEY_NOT_REGISTERED' }
    $lines = (($found.Stdout -split '\r?\n' | Where-Object { $_ -ne '' -and $_ -notmatch '^#' }) -join [Environment]::NewLine)
    $fingerprint = Invoke-Native $Keygen @('-lf','-') $lines $false
    if ($fingerprint.ExitCode -ne 0 -or $fingerprint.Stdout -notmatch [regex]::Escape($SourceHostKeyFingerprint)) {
        Stop-G5Diagnostic 'HOST_KEY_FINGERPRINT_MISMATCH'
    }
}

function Get-SshArguments([string] $IdentityPath, [string] $KnownHostsPath) {
    return @(
        '-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ConnectTimeout=10','-o','ClearAllForwardings=yes',
        '-o','LogLevel=ERROR','-o','HostKeyAlgorithms=ssh-ed25519',
        '-o',"UserKnownHostsFile=$KnownHostsPath",'-p',$SourcePort,'-l',$SourceUser,
        '-i',$IdentityPath,'-T',$SourceHost
    )
}

function Parse-SafeOutput([string] $Value) {
    if ($Utf8NoBom.GetByteCount($Value) -gt 8192 -or
        $Value -match '/home/[A-Za-z0-9._/-]+' -or
        $Value -match '(?i)(APP_KEY|DB_(CONNECTION|HOST|PORT|DATABASE|USERNAME|PASSWORD)|MAIL_(MAILER|FROM_ADDRESS|FROM_NAME)|ACCOUNT_MAIL_MAILER|OPENAI_API_KEY|PRIVATE KEY|BEARER|CREDENTIAL|TOKEN=)') {
        Stop-G5Diagnostic 'UNSAFE_REMOTE_OUTPUT'
    }
    $map = [ordered]@{}
    foreach ($line in ($Value -split '\r?\n' | Where-Object { $_ -ne '' })) {
        if ($line -notmatch '^([a-zA-Z0-9_]+)=([a-zA-Z0-9_.:-]+)$') {
            Stop-G5Diagnostic 'REMOTE_OUTPUT_CONTRACT_INVALID'
        }
        if ($map.Contains($Matches[1])) { Stop-G5Diagnostic 'REMOTE_OUTPUT_DUPLICATE_KEY' }
        $map[$Matches[1]] = $Matches[2]
    }
    if (-not $map.Contains('G5_SHARED_REQUIRED_DIAGNOSTIC')) {
        Stop-G5Diagnostic 'REMOTE_STATUS_MISSING'
    }
    return $map
}

function Assert-CorrectiveStopEvidence([string] $Path) {
    Assert-FileBinding $Path $ExpectedCorrectiveStateSha256 'CORRECTIVE1_STOP_EVIDENCE_HASH_MISMATCH'
    $state = Get-Content -Raw -LiteralPath $Path | ConvertFrom-Json
    if ($state.schema_version -ne 1 -or $state.candidate -ne $Candidate -or
        $state.helper_generation -ne 'g5-shared-state-corrective-1' -or $state.status -ne 'STOP' -or
        $state.safe_error_code -ne 'REMOTE_STEP_FAILED' -or
        $state.local_processing_substage -ne 'SOURCE_INVENTORY_PROCESS_PENDING' -or
        -not [bool] $state.production_connection_attempted -or
        -not [bool] $state.source_connection_attempted -or [bool] $state.target_connection_attempted -or
        [int] $state.remote_process_count -ne 2 -or $state.production_mutation_scope -ne 'false' -or
        [bool] $state.raw_output_stored -or [bool] $state.secret_values_output -or
        $state.step_streams.source_inventory.exit_code -ne 1 -or
        $state.step_streams.source_inventory.stdout_sha256 -ne '2c9f48b9ccec28e90a055bd5ca2e079acf47fc2ff49c7650f65ffff1006125ac' -or
        $state.step_streams.source_inventory.stdout_bytes -ne 132 -or
        $state.step_streams.source_inventory.stderr_bytes -ne 0 -or
        $state.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION -ne 'PENDING_G5_PUBLIC_ENTRY_GATE') {
        Stop-G5Diagnostic 'CORRECTIVE1_STOP_EVIDENCE_STATE_MISMATCH'
    }
}

function Assert-DiagnosticPass([object] $Result) {
    $parsed = Parse-SafeOutput $Result.Stdout
    if ($Result.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($Result.Stderr) -or
        $parsed.G5_SHARED_REQUIRED_DIAGNOSTIC -ne 'PASS') {
        Stop-G5Diagnostic 'REMOTE_DIAGNOSTIC_FAILED'
    }
    $allowedStates = @('missing','empty','null_equivalent','present')
    $diagnostics = [ordered]@{}
    $counts = @{ missing=0; empty=0; null_equivalent=0; present=0 }
    $failing = @()
    foreach ($number in 1..12) {
        $id = 'rk{0:d2}' -f $number
        $field = $id + '_state'
        if (-not $parsed.Contains($field) -or $parsed[$field] -notin $allowedStates) {
            Stop-G5Diagnostic 'DIAGNOSTIC_ID_STATE_MISSING'
        }
        $state = $parsed[$field]
        $diagnostics[$id] = $state
        $counts[$state]++
        if ($state -ne 'present') { $failing += ($id + ':' + $state) }
    }
    $failing = @($failing | Sort-Object)
    $failingPayload = if ($failing.Count -eq 0) { '' } else { ($failing -join "`n") + "`n" }
    foreach ($pair in @{
        required_id_count='12'; missing_count=[string]$counts.missing; empty_count=[string]$counts.empty
        null_equivalent_count=[string]$counts.null_equivalent; present_count=[string]$counts.present
        failing_id_set_sha256=(Get-TextSha256 $failingPayload); dotenv_parse='complete'
        storage_inventory='not_attempted'; new_target_connection='not_attempted'; secret_output='false'
        key_names_output='false'; raw_env_output='false'; source_env_hash_output='false'
        source_identity_binding='exact_legacy_source'; production_change_scope='none_read_only_source'
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }.GetEnumerator()) {
        if (-not $parsed.Contains($pair.Key) -or $parsed[$pair.Key] -ne $pair.Value) {
            Stop-G5Diagnostic 'DIAGNOSTIC_DISPOSITION_MISMATCH'
        }
    }
    return [pscustomobject]@{
        States=$diagnostics; FailingIds=@($failing | ForEach-Object { ($_ -split ':',2)[0] })
        Missing=$counts.missing; Empty=$counts.empty; NullEquivalent=$counts.null_equivalent
        Present=$counts.present; FailingHash=$parsed.failing_id_set_sha256
    }
}

try {
    $Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $ContractPath = Join-Path $PSScriptRoot 'shared-state-required-diagnostic-contract.json'
    $DiagnosticPath = Join-Path $PSScriptRoot 'diagnose-required-source.php'
    $BaseContractPath = Join-Path $PSScriptRoot 'shared-state-contract.json'
    $CorrectiveContractPath = Join-Path $PSScriptRoot 'shared-state-corrective1-contract.json'
    $AllowlistPath = Join-Path $PSScriptRoot 'shared-state-env-allowlist.txt'
    $InitialStatePath = Join-Path $Root "storage\app\release-audit\production-g5-shared-state-$Candidate\execution-state.json"
    $CorrectiveStatePath = Join-Path $Root "storage\app\release-audit\production-g5-shared-state-corrective-1-$Candidate\execution-state.json"
    $EvidenceRoot = Join-Path $Root "storage\app\release-audit\production-g5-shared-state-required-diagnostic-$Candidate"
    $StatePath = Join-Path $EvidenceRoot 'execution-state.json'
    $ReceiptPath = Join-Path $EvidenceRoot 'required-source-diagnostic.json'
    $SshRoot = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh'
    $IdentityPath = Join-Path $SshRoot $SourceIdentityLeaf
    $KnownHostsPath = Join-Path $SshRoot 'known_hosts'

    Assert-FileBinding $ContractPath $ExpectedContractSha256 'DIAGNOSTIC_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $DiagnosticPath $ExpectedDiagnosticSha256 'DIAGNOSTIC_IMPLEMENTATION_BINDING_MISMATCH'
    Assert-FileBinding $BaseContractPath $ExpectedBaseContractSha256 'BASE_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $CorrectiveContractPath $ExpectedCorrectiveContractSha256 'CORRECTIVE1_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $AllowlistPath $ExpectedAllowlistSha256 'ALLOWLIST_BINDING_MISMATCH'
    Assert-FileBinding $InitialStatePath $ExpectedInitialStateSha256 'INITIAL_STOP_EVIDENCE_BINDING_MISMATCH'
    Assert-CorrectiveStopEvidence $CorrectiveStatePath

    $contract = Get-Content -Raw -LiteralPath $ContractPath | ConvertFrom-Json
    if ($contract.contract_id -ne $ExpectedContractId -or $contract.candidate -ne $Candidate -or
        $contract.evidence_binding.corrective1_stop_state_sha256 -ne $ExpectedCorrectiveStateSha256 -or
        $contract.evidence_binding.allowlist_count -ne 172 -or
        $contract.evidence_binding.allowlist_sha256 -ne $ExpectedAllowlistSha256 -or
        $contract.required_items.Count -ne 12 -or
        ($contract.required_items.id -join ',') -ne 'rk01,rk02,rk03,rk04,rk05,rk06,rk07,rk08,rk09,rk10,rk11,rk12' -or
        -not [bool] $contract.key_name_safety.stable_diagnostic_ids_only -or
        [bool] $contract.key_name_safety.execution_evidence_key_names_allowed -or
        [bool] $contract.production_mutation_authorized -or
        [bool] $contract.shared_state_creation_authorized -or
        [bool] $contract.required_contract_change_authorized -or
        $contract.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION -ne 'PENDING_G5_PUBLIC_ENTRY_GATE') {
        Stop-G5Diagnostic 'DIAGNOSTIC_CONTRACT_MISMATCH'
    }

    if (Test-Path -LiteralPath $EvidenceRoot) {
        Stop-G5Diagnostic 'REQUIRED_DIAGNOSTIC_ATTEMPT_ALREADY_RECORDED'
    }

    $ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue
    $keygen = Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if ($null -eq $ssh -or $null -eq $keygen) { Stop-G5Diagnostic 'OPENSSH_CLIENT_UNAVAILABLE' }
    Assert-KeyFingerprint $keygen.Source $IdentityPath
    Assert-HostKey $keygen.Source $KnownHostsPath
    $sshArgs = Get-SshArguments $IdentityPath $KnownHostsPath
    $diagnosticSource = Read-LfText $DiagnosticPath

    if ($VerifyOnly) {
        $fixture = Parse-SafeOutput "G5_SHARED_REQUIRED_DIAGNOSTIC=STOP`nsafe_error_code=VERIFY_ONLY_FIXTURE`nsecret_output=false`nkey_names_output=false`nraw_env_output=false`nsource_env_hash_output=false`nproduction_change_scope=none_read_only_source`nnext_action=RETURN_TO_HUMAN_CHATGPT`n"
        if ($fixture.G5_SHARED_REQUIRED_DIAGNOSTIC -ne 'STOP') { Stop-G5Diagnostic 'SAFE_OUTPUT_PARSER_SELF_TEST_FAILED' }
        Write-Output 'G5_SHARED_REQUIRED_DIAGNOSTIC_VERIFY_ONLY=PASS'
        Write-Output "candidate=$Candidate"
        Write-Output "contract_sha256=$ExpectedContractSha256"
        Write-Output "diagnostic_sha256=$ExpectedDiagnosticSha256"
        Write-Output "corrective1_stop_state_sha256=$ExpectedCorrectiveStateSha256"
        Write-Output 'required_id_count=12'
        Write-Output 'stable_diagnostic_ids_only=true'
        Write-Output 'key_names_output=false'
        Write-Output 'secret_output=false'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'source_connection_attempted=false'
        Write-Output 'target_connection_attempted=false'
        Write-Output 'production_mutation=false'
        Write-Output 'diagnostic_operation=not_executed'
        Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
        exit 0
    }

    [IO.Directory]::CreateDirectory($EvidenceRoot) | Out-Null
    $State = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='ATTEMPT_STARTED'
        contract_sha256=$ExpectedContractSha256; diagnostic_sha256=$ExpectedDiagnosticSha256
        initial_stop_state_sha256=$ExpectedInitialStateSha256
        corrective1_stop_state_sha256=$ExpectedCorrectiveStateSha256
        required_id_count=12; production_connection_attempted=$false; source_connection_attempted=$false
        target_connection_attempted=$false; remote_process_count=0; production_mutation_scope='false'
        shared_state_created=$false; raw_output_stored=$false; secret_values_output=$false
        key_names_output=$false; source_env_hash_output=$false; diagnostic_states=$null
        failing_ids=$null; step_streams=[ordered]@{}; failure_stage='SOURCE_REQUIRED_READ_ONLY_DIAGNOSTIC'
        safe_error_code=$null; cleanup_state='not_required'; rollback_state='not_required'
        transient_residual_count=0; retry_available=$false; deploy_authorized=$false
        local_state_generation=0
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
    }
    Save-State

    $FailureStage = 'SOURCE_REQUIRED_READ_ONLY_DIAGNOSTIC'
    $result = Invoke-Native $ssh.Source ($sshArgs + @('php','--',$Confirmation,$SourceEnv)) $diagnosticSource $true
    $State.step_streams.source_required_diagnostic = [ordered]@{
        exit_code=[int]$result.ExitCode; stdout_sha256=Get-TextSha256 $result.Stdout
        stdout_bytes=$Utf8NoBom.GetByteCount($result.Stdout); stderr_sha256=Get-TextSha256 $result.Stderr
        stderr_bytes=$Utf8NoBom.GetByteCount($result.Stderr); raw_output_stored=$false
    }
    Save-State
    $diagnostic = Assert-DiagnosticPass $result

    $receipt = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='PASS'
        contract_sha256=$ExpectedContractSha256; diagnostic_sha256=$ExpectedDiagnosticSha256
        evidence_binding=[ordered]@{
            initial_stop_state_sha256=$ExpectedInitialStateSha256
            corrective1_stop_state_sha256=$ExpectedCorrectiveStateSha256
            base_contract_sha256=$ExpectedBaseContractSha256
            corrective1_contract_sha256=$ExpectedCorrectiveContractSha256
            allowlist_sha256=$ExpectedAllowlistSha256
        }
        source=[ordered]@{
            binding='exact_legacy_source'; host=$SourceHost; user=$SourceUser; port=[int]$SourcePort
            read_only=$true; diagnostic_states=$diagnostic.States; failing_ids=$diagnostic.FailingIds
            counts=[ordered]@{
                missing=$diagnostic.Missing; empty=$diagnostic.Empty
                null_equivalent=$diagnostic.NullEquivalent; present=$diagnostic.Present
            }
            failing_id_set_sha256=$diagnostic.FailingHash
        }
        operation=[ordered]@{
            production_connection_attempted=$true; source_connection_attempted=$true
            target_connection_attempted=$false; remote_process_count=1; production_mutation_scope='false'
            shared_state_created=$false; storage_inventory='not_attempted'; raw_output_stored=$false
            secret_values_output=$false; key_names_output=$false; source_env_hash_output=$false
            cleanup_state='not_required'; rollback_state='not_required'; transient_residual_count=0
            retry_available=$false; deploy_authorized=$false
        }
        step_streams=$State.step_streams
        required_contract_disposition='unchanged_pending_human_reconciliation'
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }
    Save-Json $ReceiptPath $receipt
    $State.status = 'PASS'
    $State.diagnostic_states = $diagnostic.States
    $State.failing_ids = $diagnostic.FailingIds
    $State.failure_stage = 'COMPLETE'
    Save-State

    Write-Output 'G5_SHARED_REQUIRED_DIAGNOSTIC=PASS'
    foreach ($id in $diagnostic.States.Keys) { Write-Output ($id + '_state=' + $diagnostic.States[$id]) }
    Write-Output "missing_count=$($diagnostic.Missing)"
    Write-Output "empty_count=$($diagnostic.Empty)"
    Write-Output "null_equivalent_count=$($diagnostic.NullEquivalent)"
    Write-Output "present_count=$($diagnostic.Present)"
    Write-Output "failing_id_set_sha256=$($diagnostic.FailingHash)"
    Write-Output 'required_contract_disposition=unchanged_pending_human_reconciliation'
    Write-Output 'production_connection_attempted=true'
    Write-Output 'source_connection_attempted=true'
    Write-Output 'target_connection_attempted=false'
    Write-Output 'production_mutation=false'
    Write-Output 'shared_state_created=false'
    Write-Output 'storage_inventory=not_attempted'
    Write-Output 'raw_output_stored=false'
    Write-Output 'secret_values_output=false'
    Write-Output 'key_names_output=false'
    Write-Output 'source_env_hash_output=false'
    Write-Output 'retry_available=false'
    Write-Output 'deploy_authorized=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
} catch {
    $safeCode = Get-SafeErrorCode $_.Exception
    $statePersistence = 'not_applicable'
    if ($null -ne $State) {
        try {
            $State.status = 'STOP'
            $State.safe_error_code = $safeCode
            $State.failure_stage = $FailureStage
            $State.production_connection_attempted = $ConnectionAttempted
            $State.source_connection_attempted = $SourceConnectionAttempted
            $State.target_connection_attempted = $TargetConnectionAttempted
            $State.remote_process_count = $RemoteProcessCount
            Save-State
            $statePersistence = 'saved'
        } catch {
            $statePersistence = 'failed'
        }
    }
    Write-Output 'G5_SHARED_REQUIRED_DIAGNOSTIC=STOP'
    Write-Output "safe_error_code=$safeCode"
    Write-Output "failure_stage=$FailureStage"
    Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "source_connection_attempted=$($SourceConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "target_connection_attempted=$($TargetConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output 'production_mutation=false'
    Write-Output 'shared_state_created=false'
    Write-Output 'raw_output_stored=false'
    Write-Output 'secret_values_output=false'
    Write-Output 'key_names_output=false'
    Write-Output 'source_env_hash_output=false'
    Write-Output "local_state_persistence=$statePersistence"
    Write-Output 'cleanup_state=not_required'
    Write-Output 'rollback_state=not_required'
    Write-Output 'transient_residual_count=0'
    Write-Output 'retry_available=false'
    Write-Output 'deploy_authorized=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}
