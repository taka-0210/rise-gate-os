[CmdletBinding()]
param(
    [switch] $VerifyOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$HelperGeneration = 'g5-primary-mailer-diagnostic-v1'
$ExpectedContractId = 'company-os.ir1.g5-shared-state-primary-mailer-diagnostic.v1'
$ExpectedContractSha256 = '3e6203c88cbe8e1f843bb3397fc512b6daafba28637b067a72652bea42ea08b6'
$ExpectedSourceDiagnosticSha256 = 'e66ad708648c28494606902a5d0d0e270dd016cf020950e71f727dfebc8317ea'
$ExpectedTargetCapabilitySha256 = '362efb10ae5a4752b28469c4c2b670b351bf514f55bc6edd288e2fe70ec9eec4'
$ExpectedRequiredStateSha256 = '570d8d67eb5a035b5c92ede17c5f42fd6fafa65e6de49d73385201bedaacae47'
$ExpectedRequiredReceiptSha256 = '9060f96a9e001499a32949b475d83b4d33f882c31f414a6578029ad0b7507bc7'
$ExpectedRequiredContractSha256 = 'b124b9f9688fdda86a089e80525bb608b955b7a30a2589c65d3c3303fa1a8f78'
$ExpectedBaseContractSha256 = 'cc11ad5a67e0f3f869da15091d91740f9e4d584d90995bd86d38ae22d7a282d1'
$ExpectedCorrectiveContractSha256 = '1637b67d6acc95c50e2433d9e48cb21328522d357ae7468c0bb1b973fa443064'
$ExpectedAllowlistSha256 = 'd408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec'
$ExpectedTargetKnownHostsSha256 = 'ba34cd1acb3c594d282a6c78a4352971f9f4831097c30f4423f6a326ceaa8983'

$SourceHost = 'sv17033.xserver.jp'
$SourceUser = 'xs257823'
$SourcePort = '10022'
$SourceIdentityLeaf = 'codex-company-os-production'
$SourceIdentityFingerprint = 'SHA256:d/dPUPa6KjfXVOpG1Sqqp66GMFjYtxYUR1dqhaRBQBg'
$SourceHostKeyFingerprint = 'SHA256:lkUHlNS7K7nVe/slV97qC08nvzqNzsgUsVD9p2Q1KCs'
$SourceEnv = '/home/xs257823/rise-gate.com/rise-gate-os/.env'

$TargetHost = 'sv17169.xserver.jp'
$TargetUser = 'xs377816'
$TargetPort = '10022'
$TargetIdentityLeaf = 'codex-company-os-target-production'
$TargetIdentityFingerprint = 'SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g'
$TargetHostKeyFingerprint = 'SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8'

$Confirmation = 'IR1-G5-PRIMARY-MAILER-DIAGNOSTIC'
$Utf8NoBom = [Text.UTF8Encoding]::new($false)
$ConnectionAttempted = $false
$SourceConnectionAttempted = $false
$TargetConnectionAttempted = $false
$RemoteProcessCount = 0
$State = $null
$StatePath = $null
$StateWriteGeneration = 0
$FailureStage = 'LOCAL_PRECONDITIONS'

function Stop-G5PrimaryMailer([string] $Code) {
    throw [InvalidOperationException]::new($Code)
}

function Get-SafeErrorCode([Exception] $Exception) {
    if ($Exception.Message -match '^[A-Z][A-Z0-9_]{2,100}$') { return $Exception.Message }
    return 'UNEXPECTED_LOCAL_FAILURE'
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

function Read-LfText([string] $Path) {
    return ([IO.File]::ReadAllText($Path)).Replace("`r`n", "`n").Replace("`r", "`n")
}

function Save-Json([string] $Path, [object] $Value) {
    $json = $Value | ConvertTo-Json -Depth 14
    $temporaryPath = $Path + '.tmp'
    $backupPath = $Path + '.previous'
    if ([IO.File]::Exists($temporaryPath) -or [IO.File]::Exists($backupPath)) {
        Stop-G5PrimaryMailer 'STATE_PERSISTENCE_RESIDUAL_PRESENT'
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
            Stop-G5PrimaryMailer 'NATIVE_ARGUMENT_REJECTED'
        }
    }
}

function Invoke-Native(
    [string] $File,
    [string[]] $Arguments,
    [AllowNull()][string] $InputText,
    [ValidateSet('none','source','target')][string] $ConnectionRole
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
        if (-not $process.Start()) { Stop-G5PrimaryMailer 'NATIVE_PROCESS_START_FAILED' }
        if ($ConnectionRole -ne 'none') {
            $script:ConnectionAttempted = $true
            $script:RemoteProcessCount++
            if ($ConnectionRole -eq 'source') { $script:SourceConnectionAttempted = $true }
            if ($ConnectionRole -eq 'target') { $script:TargetConnectionAttempted = $true }
            if ($null -ne $script:State) {
                $script:State.production_connection_attempted = $true
                $script:State.source_connection_attempted = $script:SourceConnectionAttempted
                $script:State.target_connection_attempted = $script:TargetConnectionAttempted
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

function Record-StepMetadata([string] $Name, [object] $Result) {
    $script:State.step_streams[$Name] = [ordered]@{
        exit_code=[int] $Result.ExitCode
        stdout_sha256=Get-TextSha256 $Result.Stdout
        stdout_bytes=$Utf8NoBom.GetByteCount($Result.Stdout)
        stderr_sha256=Get-TextSha256 $Result.Stderr
        stderr_bytes=$Utf8NoBom.GetByteCount($Result.Stderr)
        raw_output_stored=$false
    }
    Save-State
}

function Assert-FileBinding([string] $Path, [string] $Hash, [string] $Code) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf) -or (Get-FileSha256 $Path) -ne $Hash) {
        Stop-G5PrimaryMailer $Code
    }
}

function Assert-KeyFingerprint([string] $Keygen, [string] $Path, [string] $Fingerprint) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { Stop-G5PrimaryMailer 'SSH_IDENTITY_MISSING' }
    $result = Invoke-Native $Keygen @('-lf',$Path) $null 'none'
    if ($result.ExitCode -ne 0 -or $result.Stdout -notmatch [regex]::Escape($Fingerprint)) {
        Stop-G5PrimaryMailer 'SSH_IDENTITY_FINGERPRINT_MISMATCH'
    }
}

function Assert-HostKey(
    [string] $Keygen,
    [string] $KnownHostsPath,
    [string] $HostName,
    [string] $Port,
    [string] $Fingerprint
) {
    if (-not (Test-Path -LiteralPath $KnownHostsPath -PathType Leaf)) {
        Stop-G5PrimaryMailer 'KNOWN_HOSTS_MISSING'
    }
    $lookup = "[$HostName]:$Port"
    $found = Invoke-Native $Keygen @('-F',$lookup,'-f',$KnownHostsPath) $null 'none'
    if ($found.ExitCode -ne 0) { Stop-G5PrimaryMailer 'HOST_KEY_NOT_REGISTERED' }
    $lines = (($found.Stdout -split '\r?\n' | Where-Object { $_ -ne '' -and $_ -notmatch '^#' }) -join [Environment]::NewLine)
    $result = Invoke-Native $Keygen @('-lf','-') $lines 'none'
    if ($result.ExitCode -ne 0 -or $result.Stdout -notmatch [regex]::Escape($Fingerprint)) {
        Stop-G5PrimaryMailer 'HOST_KEY_FINGERPRINT_MISMATCH'
    }
}

function Get-SshArguments(
    [string] $IdentityPath,
    [string] $KnownHostsPath,
    [string] $HostName,
    [string] $UserName,
    [string] $Port
) {
    return @(
        '-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ConnectTimeout=10','-o','ClearAllForwardings=yes',
        '-o','LogLevel=ERROR','-o','HostKeyAlgorithms=ssh-ed25519',
        '-o',"UserKnownHostsFile=$KnownHostsPath",'-p',$Port,'-l',$UserName,
        '-i',$IdentityPath,'-T',$HostName
    )
}

function Parse-SafeOutput([string] $Value, [string[]] $AllowedKeys, [string] $StatusKey) {
    if ($Utf8NoBom.GetByteCount($Value) -gt 8192 -or
        $Value -match '/home/[A-Za-z0-9._/-]+' -or $Value -match '@' -or
        $Value -match '://' -or $Value -match '-----BEGIN') {
        Stop-G5PrimaryMailer 'UNSAFE_REMOTE_OUTPUT'
    }
    $map = [ordered]@{}
    foreach ($line in ($Value -split '\r?\n' | Where-Object { $_ -ne '' })) {
        if ($line -notmatch '^([a-zA-Z0-9_]+)=([a-zA-Z0-9_.:-]+)$') {
            Stop-G5PrimaryMailer 'REMOTE_OUTPUT_CONTRACT_INVALID'
        }
        $key = $Matches[1]
        if ($AllowedKeys -notcontains $key) { Stop-G5PrimaryMailer 'REMOTE_OUTPUT_KEY_NOT_ALLOWED' }
        if ($map.Contains($key)) { Stop-G5PrimaryMailer 'REMOTE_OUTPUT_DUPLICATE_KEY' }
        $map[$key] = $Matches[2]
    }
    if (-not $map.Contains($StatusKey)) { Stop-G5PrimaryMailer 'REMOTE_STATUS_MISSING' }
    return $map
}

function Assert-RequiredDiagnosticEvidence([string] $StatePath, [string] $ReceiptPath) {
    Assert-FileBinding $StatePath $ExpectedRequiredStateSha256 'REQUIRED_DIAGNOSTIC_STATE_HASH_MISMATCH'
    Assert-FileBinding $ReceiptPath $ExpectedRequiredReceiptSha256 'REQUIRED_DIAGNOSTIC_RECEIPT_HASH_MISMATCH'
    $requiredState = Get-Content -Raw -LiteralPath $StatePath | ConvertFrom-Json
    $requiredReceipt = Get-Content -Raw -LiteralPath $ReceiptPath | ConvertFrom-Json
    if ($requiredState.status -ne 'PASS' -or $requiredReceipt.status -ne 'PASS' -or
        $requiredState.candidate -ne $Candidate -or $requiredReceipt.candidate -ne $Candidate -or
        $requiredState.contract_sha256 -ne $ExpectedRequiredContractSha256 -or
        $requiredReceipt.contract_sha256 -ne $ExpectedRequiredContractSha256 -or
        @($requiredState.failing_ids).Count -ne 1 -or $requiredState.failing_ids[0] -ne 'rk11' -or
        $requiredState.diagnostic_states.rk11 -ne 'missing' -or
        @($requiredReceipt.source.failing_ids).Count -ne 1 -or $requiredReceipt.source.failing_ids[0] -ne 'rk11' -or
        [bool] $requiredState.target_connection_attempted -or
        $requiredState.production_mutation_scope -ne 'false' -or
        [bool] $requiredState.secret_values_output -or [bool] $requiredState.raw_output_stored) {
        Stop-G5PrimaryMailer 'REQUIRED_DIAGNOSTIC_EVIDENCE_MISMATCH'
    }
}

function Assert-CandidateBlob(
    [string] $Git,
    [string] $Path,
    [string] $ExpectedBlob
) {
    $result = Invoke-Native $Git @('rev-parse',"$Candidate`:$Path") $null 'none'
    if ($result.ExitCode -ne 0 -or $result.Stderr -ne '' -or $result.Stdout.Trim() -ne $ExpectedBlob) {
        Stop-G5PrimaryMailer 'CANDIDATE_BLOB_BINDING_MISMATCH'
    }
}

try {
    $Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $ContractPath = Join-Path $PSScriptRoot 'shared-state-primary-mailer-contract.json'
    $SourceDiagnosticPath = Join-Path $PSScriptRoot 'diagnose-primary-mailer.php'
    $TargetCapabilityPath = Join-Path $PSScriptRoot 'inspect-primary-mailer-target-capability.sh'
    $BaseContractPath = Join-Path $PSScriptRoot 'shared-state-contract.json'
    $CorrectiveContractPath = Join-Path $PSScriptRoot 'shared-state-corrective1-contract.json'
    $RequiredContractPath = Join-Path $PSScriptRoot 'shared-state-required-diagnostic-contract.json'
    $AllowlistPath = Join-Path $PSScriptRoot 'shared-state-env-allowlist.txt'
    $RequiredEvidenceRoot = Join-Path $Root "storage\app\release-audit\production-g5-shared-state-required-diagnostic-$Candidate"
    $RequiredStatePath = Join-Path $RequiredEvidenceRoot 'execution-state.json'
    $RequiredReceiptPath = Join-Path $RequiredEvidenceRoot 'required-source-diagnostic.json'
    $TargetBindingRoot = Join-Path $Root "storage\app\release-audit\production-g5b-new-target-binding-$Candidate"
    $EvidenceRoot = Join-Path $Root "storage\app\release-audit\production-g5-primary-mailer-diagnostic-$Candidate"
    $StatePath = Join-Path $EvidenceRoot 'execution-state.json'
    $ReceiptPath = Join-Path $EvidenceRoot 'primary-mailer-diagnostic.json'
    $SshRoot = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh'
    $SourceIdentityPath = Join-Path $SshRoot $SourceIdentityLeaf
    $SourceKnownHostsPath = Join-Path $SshRoot 'known_hosts'
    $TargetIdentityPath = Join-Path $SshRoot $TargetIdentityLeaf
    $TargetKnownHostsPath = Join-Path $TargetBindingRoot 'known_hosts'

    Assert-FileBinding $ContractPath $ExpectedContractSha256 'PRIMARY_MAILER_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $SourceDiagnosticPath $ExpectedSourceDiagnosticSha256 'SOURCE_DIAGNOSTIC_BINDING_MISMATCH'
    Assert-FileBinding $TargetCapabilityPath $ExpectedTargetCapabilitySha256 'TARGET_CAPABILITY_BINDING_MISMATCH'
    Assert-FileBinding $BaseContractPath $ExpectedBaseContractSha256 'BASE_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $CorrectiveContractPath $ExpectedCorrectiveContractSha256 'CORRECTIVE1_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $RequiredContractPath $ExpectedRequiredContractSha256 'REQUIRED_CONTRACT_BINDING_MISMATCH'
    Assert-FileBinding $AllowlistPath $ExpectedAllowlistSha256 'ALLOWLIST_BINDING_MISMATCH'
    Assert-FileBinding $TargetKnownHostsPath $ExpectedTargetKnownHostsSha256 'TARGET_KNOWN_HOSTS_BINDING_MISMATCH'
    Assert-RequiredDiagnosticEvidence $RequiredStatePath $RequiredReceiptPath

    $contract = Get-Content -Raw -LiteralPath $ContractPath | ConvertFrom-Json
    if ($contract.contract_id -ne $ExpectedContractId -or $contract.candidate -ne $Candidate -or
        $contract.human_decision.account_mailer -ne 'same_as_primary_production_mailer' -or
        [bool] $contract.human_decision.dedicated_account_mailer -or
        [bool] $contract.human_decision.human_manual_mailer_name_input -or
        [bool] $contract.human_decision.human_manual_credential_input -or
        $contract.required_contract_reconciliation.rk11_target_value_source -ne 'exact_primary_mailer_in_memory' -or
        [bool] $contract.required_contract_reconciliation.base_required_contract_changed_by_this_preparation -or
        [bool] $contract.production_mutation_authorized -or [bool] $contract.shared_state_creation_authorized -or
        [bool] $contract.required_contract_change_authorized -or
        $contract.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION -ne 'PENDING_G5_PUBLIC_ENTRY_GATE') {
        Stop-G5PrimaryMailer 'PRIMARY_MAILER_CONTRACT_MISMATCH'
    }

    $git = Get-Command git.exe -ErrorAction SilentlyContinue
    $ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue
    $keygen = Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if ($null -eq $git -or $null -eq $ssh -or $null -eq $keygen) {
        Stop-G5PrimaryMailer 'REQUIRED_NATIVE_TOOL_UNAVAILABLE'
    }
    foreach ($binding in @(
        @('config/mail.php','e32e88da2cc82d4139c032c28b03005afc6008c6'),
        @('config/services.php','053964d3eb9264c652878012048a4bcca60735e7'),
        @('config/queue.php','79c2c0a23cd06bcb6d22ea0a2b218e22a6d51198'),
        @('config/database.php','64709ce5a3de66194ebc80ba108a336c0dfc35a4'),
        @('config/account.php','24cd9d36bbf59e07518adf5af181ba3d27c604cf'),
        @('app/Services/AccountMailDispatcher.php','2242c1bb2398e035fd2ffb2bead803d2f3ccc9e5'),
        @('app/Jobs/SendAccountActionMail.php','e1b4241b4ec424b93fe27d26d34b883c1c0d8d2f')
    )) {
        Assert-CandidateBlob $git.Source $binding[0] $binding[1]
    }

    Assert-KeyFingerprint $keygen.Source $SourceIdentityPath $SourceIdentityFingerprint
    Assert-KeyFingerprint $keygen.Source $TargetIdentityPath $TargetIdentityFingerprint
    Assert-HostKey $keygen.Source $SourceKnownHostsPath $SourceHost $SourcePort $SourceHostKeyFingerprint
    Assert-HostKey $keygen.Source $TargetKnownHostsPath $TargetHost $TargetPort $TargetHostKeyFingerprint

    $sourceArgs = Get-SshArguments $SourceIdentityPath $SourceKnownHostsPath $SourceHost $SourceUser $SourcePort
    $targetArgs = Get-SshArguments $TargetIdentityPath $TargetKnownHostsPath $TargetHost $TargetUser $TargetPort
    $sourceDiagnostic = Read-LfText $SourceDiagnosticPath
    $targetCapability = Read-LfText $TargetCapabilityPath

    if ($VerifyOnly) {
        $sourceFixture = Parse-SafeOutput "G5_PRIMARY_MAILER_DIAGNOSTIC=STOP`nsafe_error_code=VERIFY_ONLY_FIXTURE`nproduction_change_scope=none_read_only_source`nenvironment_values_output=false`nprimary_mailer_name_output=false`ncredential_values_output=false`nkey_names_output=false`nraw_env_output=false`nsource_env_hash_output=false`nnext_action=RETURN_TO_HUMAN_CHATGPT`n" @(
            'G5_PRIMARY_MAILER_DIAGNOSTIC','safe_error_code','production_change_scope','environment_values_output',
            'primary_mailer_name_output','credential_values_output','key_names_output','raw_env_output',
            'source_env_hash_output','next_action'
        ) 'G5_PRIMARY_MAILER_DIAGNOSTIC'
        if ($sourceFixture.G5_PRIMARY_MAILER_DIAGNOSTIC -ne 'STOP') {
            Stop-G5PrimaryMailer 'SAFE_OUTPUT_PARSER_SELF_TEST_FAILED'
        }
        Write-Output 'G5_PRIMARY_MAILER_DIAGNOSTIC_VERIFY_ONLY=PASS'
        Write-Output "candidate=$Candidate"
        Write-Output "contract_sha256=$ExpectedContractSha256"
        Write-Output "source_diagnostic_sha256=$ExpectedSourceDiagnosticSha256"
        Write-Output "target_capability_sha256=$ExpectedTargetCapabilitySha256"
        Write-Output 'account_mail_binding=primary_exact'
        Write-Output 'account_mail_manual_input=false'
        Write-Output 'primary_mailer_name_output=false'
        Write-Output 'credential_values_output=false'
        Write-Output 'production_connection_attempted=false'
        Write-Output 'source_connection_attempted=false'
        Write-Output 'target_connection_attempted=false'
        Write-Output 'production_mutation=false'
        Write-Output 'diagnostic_operation=not_executed'
        Write-Output 'deploy_authorized=false'
        Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
        exit 0
    }

    if (Test-Path -LiteralPath $EvidenceRoot) {
        Stop-G5PrimaryMailer 'PRIMARY_MAILER_DIAGNOSTIC_ATTEMPT_ALREADY_RECORDED'
    }
    [IO.Directory]::CreateDirectory($EvidenceRoot) | Out-Null
    $State = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='ATTEMPT_STARTED'
        contract_sha256=$ExpectedContractSha256; source_diagnostic_sha256=$ExpectedSourceDiagnosticSha256
        target_capability_sha256=$ExpectedTargetCapabilitySha256
        required_diagnostic_state_sha256=$ExpectedRequiredStateSha256
        required_diagnostic_receipt_sha256=$ExpectedRequiredReceiptSha256
        production_connection_attempted=$false; source_connection_attempted=$false
        target_connection_attempted=$false; remote_process_count=0; production_mutation_scope='false'
        shared_state_created=$false; raw_output_stored=$false; secret_values_output=$false
        environment_values_output=$false; primary_mailer_name_output=$false; credential_values_output=$false
        key_names_output=$false; source_env_hash_output=$false
        source_disposition=$null; target_capability_disposition=$null; step_streams=[ordered]@{}
        failure_stage='SOURCE_PRIMARY_MAILER_READ_ONLY_DIAGNOSTIC'; safe_error_code=$null
        cleanup_state='not_required'; rollback_state='not_required'; transient_residual_count=0
        retry_available=$false; deploy_authorized=$false; local_state_generation=0
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
    }
    Save-State

    $sourceAllowedKeys = @(
        'G5_PRIMARY_MAILER_DIAGNOSTIC','safe_error_code','primary_mailer_id','primary_transport_state',
        'primary_endpoint_presence','primary_credential_presence','from_address_state','from_name_state',
        'account_mail_binding','account_mail_value_source','account_mail_manual_input','queue_driver_id',
        'queue_config_state','queue_worker_dependency','queue_runtime_readiness','target_capability_id',
        'production_change_scope','environment_values_output','primary_mailer_name_output',
        'credential_values_output','key_names_output','raw_env_output','source_env_hash_output','next_action'
    )
    $FailureStage = 'SOURCE_PRIMARY_MAILER_READ_ONLY_DIAGNOSTIC'
    $sourceResult = Invoke-Native $ssh.Source ($sourceArgs + @('php','--',$Confirmation,$SourceEnv)) $sourceDiagnostic 'source'
    Record-StepMetadata 'source_primary_mailer_diagnostic' $sourceResult
    $source = Parse-SafeOutput $sourceResult.Stdout $sourceAllowedKeys 'G5_PRIMARY_MAILER_DIAGNOSTIC'
    $State.source_disposition = $source
    Save-State
    if ($source.G5_PRIMARY_MAILER_DIAGNOSTIC -ne 'PASS') {
        Stop-G5PrimaryMailer 'REMOTE_SOURCE_DIAGNOSTIC_STOP'
    }
    if ($sourceResult.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($sourceResult.Stderr)) {
        Stop-G5PrimaryMailer 'REMOTE_SOURCE_DIAGNOSTIC_FAILED'
    }
    foreach ($pair in @{
        primary_transport_state='delivery_capable'; from_address_state='valid'; from_name_state='present'
        account_mail_binding='primary_exact'; account_mail_value_source='derived_in_memory'
        account_mail_manual_input='false'; queue_config_state='complete'
        queue_runtime_readiness='deferred_application_release_gate'; production_change_scope='none_read_only_source'
        environment_values_output='false'; primary_mailer_name_output='false'; credential_values_output='false'
        key_names_output='false'; raw_env_output='false'; source_env_hash_output='false'
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }.GetEnumerator()) {
        if (-not $source.Contains($pair.Key) -or $source[$pair.Key] -ne $pair.Value) {
            Stop-G5PrimaryMailer 'SOURCE_DIAGNOSTIC_DISPOSITION_MISMATCH'
        }
    }
    if ($source.primary_mailer_id -notin @('mt01','mt02','mt03','mt04','mt05','mt07') -or
        $source.queue_driver_id -notin @('q01','q02','q03','q04','q05','q06','q07','q08') -or
        $source.queue_worker_dependency -notin @('required','not_required','conditional') -or
        $source.target_capability_id -notin @('tc00','tc01','tc02','tc03')) {
        Stop-G5PrimaryMailer 'SOURCE_DIAGNOSTIC_ID_INVALID'
    }
    $expectedPresence = if ($source.primary_mailer_id -eq 'mt05') { 'not_applicable' } else { 'complete' }
    if ($source.primary_endpoint_presence -ne $expectedPresence -or
        $source.primary_credential_presence -ne $expectedPresence) {
        Stop-G5PrimaryMailer 'SOURCE_TRANSPORT_PRESENCE_MISMATCH'
    }

    $target = [ordered]@{
        G5_PRIMARY_MAILER_TARGET_CAPABILITY='NOT_REQUIRED'
        target_capability_id='tc00'
        sendmail_capability='not_required'
        php_proc_open_capability='not_required'
        production_change_scope='none_read_only_target'
        secret_output='false'
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }
    if ($source.target_capability_id -ne 'tc00') {
        $FailureStage = 'TARGET_PRIMARY_MAILER_READ_ONLY_CAPABILITY'
        $targetResult = Invoke-Native $ssh.Source ($targetArgs + @('sh','-s','--',$Confirmation,$source.target_capability_id)) $targetCapability 'target'
        Record-StepMetadata 'target_primary_mailer_capability' $targetResult
        $target = Parse-SafeOutput $targetResult.Stdout @(
            'G5_PRIMARY_MAILER_TARGET_CAPABILITY','safe_error_code','target_capability_id','sendmail_capability',
            'php_proc_open_capability','production_change_scope','secret_output','next_action'
        ) 'G5_PRIMARY_MAILER_TARGET_CAPABILITY'
        $State.target_capability_disposition = $target
        Save-State
        if ($target.G5_PRIMARY_MAILER_TARGET_CAPABILITY -ne 'PASS') {
            Stop-G5PrimaryMailer 'REMOTE_TARGET_CAPABILITY_STOP'
        }
        if ($targetResult.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($targetResult.Stderr) -or
            $target.target_capability_id -ne $source.target_capability_id -or
            $target.production_change_scope -ne 'none_read_only_target' -or $target.secret_output -ne 'false' -or
            $target.next_action -ne 'RETURN_TO_HUMAN_CHATGPT') {
            Stop-G5PrimaryMailer 'REMOTE_TARGET_CAPABILITY_FAILED'
        }
    } else {
        $State.target_capability_disposition = $target
        Save-State
    }

    $receipt = [ordered]@{
        schema_version=1; candidate=$Candidate; helper_generation=$HelperGeneration; status='PASS'
        contract_sha256=$ExpectedContractSha256; source_diagnostic_sha256=$ExpectedSourceDiagnosticSha256
        target_capability_sha256=$ExpectedTargetCapabilitySha256
        evidence_binding=[ordered]@{
            required_diagnostic_state_sha256=$ExpectedRequiredStateSha256
            required_diagnostic_receipt_sha256=$ExpectedRequiredReceiptSha256
            required_diagnostic_contract_sha256=$ExpectedRequiredContractSha256
            base_contract_sha256=$ExpectedBaseContractSha256
            corrective1_contract_sha256=$ExpectedCorrectiveContractSha256
            allowlist_sha256=$ExpectedAllowlistSha256
        }
        human_decision=[ordered]@{
            account_mailer='same_as_primary_production_mailer'; dedicated_account_mailer=$false
            manual_mailer_or_credential_input=$false; binding='primary_exact_derived_in_memory'
        }
        source=[ordered]@{
            binding='exact_legacy_source'; host=$SourceHost; user=$SourceUser; port=[int]$SourcePort
            read_only=$true; disposition=$source
        }
        target=[ordered]@{
            binding='exact_new_target'; host=$TargetHost; user=$TargetUser; port=[int]$TargetPort
            connection_required=($source.target_capability_id -ne 'tc00'); disposition=$target
        }
        operation=[ordered]@{
            production_connection_attempted=$ConnectionAttempted
            source_connection_attempted=$SourceConnectionAttempted
            target_connection_attempted=$TargetConnectionAttempted
            remote_process_count=$RemoteProcessCount; production_mutation_scope='false'
            shared_state_created=$false; raw_output_stored=$false; secret_values_output=$false
            environment_values_output=$false; primary_mailer_name_output=$false
            credential_values_output=$false; key_names_output=$false; source_env_hash_output=$false
            cleanup_state='not_required'; rollback_state='not_required'; transient_residual_count=0
            retry_available=$false; deploy_authorized=$false
        }
        step_streams=$State.step_streams
        queue_runtime_readiness='deferred_application_release_gate'
        required_contract_disposition='unchanged_future_corrective_required'
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION='PENDING_G5_PUBLIC_ENTRY_GATE'
        next_action='RETURN_TO_HUMAN_CHATGPT'
    }
    Save-Json $ReceiptPath $receipt
    $State.status = 'PASS'
    $State.failure_stage = 'COMPLETE'
    $State.safe_error_code = $null
    Save-State

    Write-Output 'G5_PRIMARY_MAILER_DIAGNOSTIC=PASS'
    Write-Output "primary_mailer_id=$($source.primary_mailer_id)"
    Write-Output 'primary_transport_state=delivery_capable'
    Write-Output "primary_credential_presence=$($source.primary_credential_presence)"
    Write-Output 'from_identity_state=complete'
    Write-Output "queue_driver_id=$($source.queue_driver_id)"
    Write-Output "queue_worker_dependency=$($source.queue_worker_dependency)"
    Write-Output 'queue_runtime_readiness=deferred_application_release_gate'
    Write-Output 'account_mail_binding=primary_exact'
    Write-Output 'account_mail_manual_input=false'
    Write-Output 'primary_mailer_name_output=false'
    Write-Output 'credential_values_output=false'
    Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "source_connection_attempted=$($SourceConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "target_connection_attempted=$($TargetConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output 'production_mutation=false'
    Write-Output 'shared_state_created=false'
    Write-Output 'retry_available=false'
    Write-Output 'deploy_authorized=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
} catch {
    $safeCode = Get-SafeErrorCode $_.Exception
    $statePersistence = 'not_applicable'
    if ($null -ne $State) {
        $State.status = 'STOP'
        $State.safe_error_code = $safeCode
        $State.failure_stage = $FailureStage
        $State.production_connection_attempted = $ConnectionAttempted
        $State.source_connection_attempted = $SourceConnectionAttempted
        $State.target_connection_attempted = $TargetConnectionAttempted
        $State.remote_process_count = $RemoteProcessCount
        try { Save-State; $statePersistence = 'saved' } catch { $statePersistence = 'failed' }
    }
    Write-Output 'G5_PRIMARY_MAILER_DIAGNOSTIC=STOP'
    Write-Output "safe_error_code=$safeCode"
    Write-Output "failure_stage=$FailureStage"
    Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "source_connection_attempted=$($SourceConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "target_connection_attempted=$($TargetConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output 'production_mutation=false'
    Write-Output 'shared_state_created=false'
    Write-Output 'secret_values_output=false'
    Write-Output 'primary_mailer_name_output=false'
    Write-Output 'credential_values_output=false'
    Write-Output "local_state_persistence=$statePersistence"
    Write-Output 'retry_available=false'
    Write-Output 'deploy_authorized=false'
    Write-Output 'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'
    Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}
