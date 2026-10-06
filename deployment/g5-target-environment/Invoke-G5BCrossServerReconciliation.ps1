[CmdletBinding()]
param([switch] $VerifyOnly)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$ContractId = 'company-os.ir1.g5b-cross-server-reconciliation.v1'
$Utf8NoBom = [Text.UTF8Encoding]::new($false)

function Stop-Reconciliation([string] $Code) {
    throw "G5B_RECONCILIATION_STOP:$Code"
}

function Get-Sha256([string] $Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-TextSha256([string] $Value) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes($Value)
        return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function Read-Json([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        Stop-Reconciliation 'INPUT_EVIDENCE_MISSING'
    }
    return Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
}

function Assert-Equal($Actual, $Expected, [string] $Code) {
    if ($Actual -ne $Expected) { Stop-Reconciliation $Code }
}

function Assert-True([bool] $Value, [string] $Code) {
    if (-not $Value) { Stop-Reconciliation $Code }
}

function Save-Json([string] $Path, $Value) {
    $json = $Value | ConvertTo-Json -Depth 16
    [IO.File]::WriteAllText($Path, $json + [Environment]::NewLine, $Utf8NoBom)
}

function Get-JstTimestamp() {
    $zone = [TimeZoneInfo]::FindSystemTimeZoneById('Tokyo Standard Time')
    return [TimeZoneInfo]::ConvertTimeFromUtc([DateTime]::UtcNow, $zone).ToString('yyyy-MM-ddTHH:mm:sszzz')
}

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ContractPath = Join-Path $PSScriptRoot 'g5b-cross-server-reconciliation-contract.json'
$Contract = Read-Json $ContractPath

Assert-Equal $Contract.contract_id $ContractId 'CONTRACT_ID_MISMATCH'
Assert-Equal $Contract.candidate $Candidate 'CANDIDATE_MISMATCH'
Assert-Equal $Contract.operation 'production_free_local_evidence_reconciliation' 'OPERATION_SCOPE_MISMATCH'
foreach ($property in @(
    'production_connection_authorized',
    'ssh_connection_authorized',
    'http_request_authorized',
    'filesystem_mutation_authorized',
    'dns_or_ssl_change_authorized',
    'existing_evidence_mutation_authorized'
)) {
    Assert-Equal ([bool] $Contract.safety.$property) $false 'SAFETY_CONTRACT_INVALID'
}

$Inputs = @{}
$InputReceipts = @()
foreach ($input in @($Contract.inputs)) {
    $path = Join-Path $Root ([string] $input.relative_path -replace '/', '\')
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        Stop-Reconciliation 'INPUT_EVIDENCE_MISSING'
    }
    $actualHash = Get-Sha256 $path
    Assert-Equal $actualHash ([string] $input.sha256) 'INPUT_EVIDENCE_HASH_MISMATCH'
    $Inputs[[string] $input.id] = $path
    $InputReceipts += [ordered]@{
        id = [string] $input.id
        relative_path = [string] $input.relative_path
        sha256 = $actualHash
    }
}

$TargetContract = Read-Json $Inputs.target_contract
$LegacyState = Read-Json $Inputs.legacy_execution_state
$LegacyObservation = Read-Json $Inputs.legacy_observation
$NewHostKey = Read-Json $Inputs.new_target_host_key_receipt
$NewState = Read-Json $Inputs.new_target_execution_state
$NewObservation = Read-Json $Inputs.new_target_observation
$LegacyBindingRecord = Get-Content -LiteralPath $Inputs.legacy_binding_record -Raw
$NewHostKeyRecord = Get-Content -LiteralPath $Inputs.new_target_host_key_record -Raw

Assert-Equal $TargetContract.source_commit $Candidate 'TARGET_CONTRACT_CANDIDATE_MISMATCH'
Assert-Equal $TargetContract.production_urls.application_target 'app.company-os.jp' 'TARGET_HOST_MISMATCH'
Assert-Equal $TargetContract.relative_paths.target_public_entry 'company-os.jp/public_html/app.company-os.jp' 'TARGET_PATH_MISMATCH'
Assert-Equal $TargetContract.g6_handoff.dns_change 'G6_ONLY' 'G6_DNS_BOUNDARY_MISMATCH'
Assert-Equal $TargetContract.g6_handoff.ssl_change 'G6_ONLY' 'G6_SSL_BOUNDARY_MISMATCH'

foreach ($state in @($LegacyState, $NewState)) {
    Assert-Equal $state.candidate $Candidate 'STATE_CANDIDATE_MISMATCH'
    Assert-Equal $state.status 'STOP' 'STATE_STATUS_MISMATCH'
    Assert-Equal ([bool] $state.production_connection_attempted) $true 'PRIOR_CONNECTION_EVIDENCE_MISSING'
    Assert-Equal ([bool] $state.production_mutation) $false 'PRIOR_MUTATION_DETECTED'
    Assert-Equal ([bool] $state.native_process_started) $true 'NATIVE_PROCESS_EVIDENCE_MISSING'
    Assert-Equal ([int] $state.remote_exit_code) 0 'REMOTE_EXIT_MISMATCH'
    Assert-Equal $state.ssh_authentication 'established' 'SSH_AUTHENTICATION_MISMATCH'
    Assert-Equal $state.remote_shell 'established' 'REMOTE_SHELL_MISMATCH'
    Assert-Equal $state.remote_contract 'complete' 'REMOTE_CONTRACT_INCOMPLETE'
    Assert-Equal $state.safe_error_code 'TARGET_ANCHOR_REBINDING_FAILED' 'PRIOR_STOP_REASON_MISMATCH'
}

foreach ($observation in @($LegacyObservation, $NewObservation)) {
    Assert-Equal $observation.candidate $Candidate 'OBSERVATION_CANDIDATE_MISMATCH'
    Assert-Equal $observation.status 'OBSERVED' 'OBSERVATION_STATUS_MISMATCH'
    Assert-Equal ([bool] $observation.production_mutation) $false 'OBSERVATION_MUTATION_DETECTED'
    Assert-Equal $observation.database_connection 'not_attempted' 'DATABASE_BOUNDARY_MISMATCH'
    Assert-Equal $observation.evidence.G5B_TARGET_DISCOVERY 'PASS' 'REMOTE_DISCOVERY_INCOMPLETE'
    Assert-Equal $observation.evidence.posix_capability_rehearsal 'not_executed' 'G5C_ALREADY_EXECUTED'
    Assert-Equal $observation.evidence.production_mutation 'false' 'REMOTE_MUTATION_DETECTED'
}

$Legacy = $LegacyObservation.evidence
$NewTarget = $NewObservation.evidence
$LegacyEndpoint = $Contract.trusted_endpoints.legacy
$NewEndpoint = $Contract.trusted_endpoints.new_target

Assert-Equal $Legacy.remote_host_fqdn $LegacyEndpoint.host 'LEGACY_HOST_MISMATCH'
Assert-Equal $Legacy.login_uid $LegacyEndpoint.login_uid 'LEGACY_LOGIN_UID_MISMATCH'
Assert-Equal $Legacy.actual_home_sha256 $LegacyEndpoint.actual_home_sha256 'LEGACY_HOME_MISMATCH'
Assert-Equal $Legacy.legacy_domain_root_state 'directory' 'LEGACY_DOMAIN_STATE_MISMATCH'
Assert-Equal $Legacy.legacy_application_root_state 'directory' 'LEGACY_APPLICATION_STATE_MISMATCH'
Assert-Equal $Legacy.legacy_public_entry_state 'directory' 'LEGACY_PUBLIC_STATE_MISMATCH'
Assert-Equal $Legacy.target_domain_root_state 'absent' 'LEGACY_SERVER_TARGET_COLLISION'
Assert-Equal $Legacy.target_public_entry_state 'absent' 'LEGACY_SERVER_PUBLIC_COLLISION'
Assert-Equal $Legacy.legacy_physical_separation 'unknown' 'LEGACY_REMOTE_OBSERVATION_CHANGED'
Assert-Equal $Legacy.target_anchor_state 'review_required' 'LEGACY_ANCHOR_OBSERVATION_CHANGED'
Assert-True ($LegacyBindingRecord.Contains([string] $LegacyEndpoint.host)) 'LEGACY_BINDING_RECORD_HOST_MISSING'
Assert-True ($LegacyBindingRecord.Contains([string] $LegacyEndpoint.host_key_fingerprint)) 'LEGACY_BINDING_RECORD_HOST_KEY_MISSING'

Assert-Equal $NewTarget.remote_host_fqdn $NewEndpoint.host 'NEW_TARGET_HOST_MISMATCH'
Assert-Equal $NewTarget.login_uid $NewEndpoint.login_uid 'NEW_TARGET_LOGIN_UID_MISMATCH'
Assert-Equal $NewTarget.actual_home_sha256 $NewEndpoint.actual_home_sha256 'NEW_TARGET_HOME_MISMATCH'
Assert-Equal (Get-TextSha256 ([string] $NewEndpoint.actual_home)) $NewTarget.actual_home_sha256 'NEW_TARGET_HOME_HASH_MISMATCH'
Assert-Equal (Get-TextSha256 ([string] $NewEndpoint.target_public_entry)) $NewTarget.target_public_entry_path_sha256 'NEW_TARGET_PATH_HASH_MISMATCH'
Assert-Equal $NewTarget.target_public_entry_path_sha256 $NewEndpoint.target_public_entry_sha256 'NEW_TARGET_EXPECTED_PATH_MISMATCH'
Assert-Equal $NewTarget.target_domain_root_state 'directory' 'NEW_TARGET_DOMAIN_STATE_MISMATCH'
Assert-Equal $NewTarget.target_public_parent_state 'directory' 'NEW_TARGET_PUBLIC_PARENT_STATE_MISMATCH'
Assert-Equal $NewTarget.target_public_entry_state 'directory' 'NEW_TARGET_PUBLIC_ENTRY_STATE_MISMATCH'
Assert-Equal $NewTarget.target_topology_root_state 'absent' 'NEW_TARGET_TOPOLOGY_COLLISION'
Assert-Equal $NewTarget.target_domain_owner_matches_login 'yes' 'NEW_TARGET_DOMAIN_OWNER_MISMATCH'
Assert-Equal $NewTarget.target_public_entry_owner_matches_login 'yes' 'NEW_TARGET_PUBLIC_OWNER_MISMATCH'
Assert-Equal $NewTarget.domain_public_same_device 'yes' 'NEW_TARGET_DEVICE_MISMATCH'
Assert-Equal $NewTarget.target_filesystem_type 'xfs' 'NEW_TARGET_FILESYSTEM_MISMATCH'
Assert-Equal $NewTarget.legacy_domain_root_state 'absent' 'LEGACY_PATH_PRESENT_ON_NEW_TARGET'
Assert-Equal $NewTarget.legacy_application_root_state 'absent' 'LEGACY_APPLICATION_PRESENT_ON_NEW_TARGET'
Assert-Equal $NewTarget.legacy_public_entry_state 'absent' 'LEGACY_PUBLIC_PRESENT_ON_NEW_TARGET'
Assert-Equal $NewTarget.legacy_physical_separation 'unknown' 'NEW_TARGET_REMOTE_OBSERVATION_CHANGED'
Assert-Equal $NewTarget.target_anchor_state 'review_required' 'NEW_TARGET_ANCHOR_OBSERVATION_CHANGED'
Assert-Equal $NewTarget.target_public_entry_count '3' 'PUBLIC_ENTRY_COUNT_MISMATCH'
Assert-Equal $NewTarget.default_index_state 'file' 'DEFAULT_INDEX_STATE_MISMATCH'
Assert-Equal $NewTarget.default_index_bytes '2744' 'DEFAULT_INDEX_SIZE_MISMATCH'
Assert-Equal (@($NewState.target_binding_mismatches) -join ',') 'legacy_physical_separation,target_anchor_state' 'NEW_TARGET_MISMATCH_SET_CHANGED'

Assert-Equal $NewHostKey.candidate $Candidate 'HOST_KEY_CANDIDATE_MISMATCH'
Assert-Equal $NewHostKey.status 'PASS' 'HOST_KEY_STATUS_MISMATCH'
Assert-Equal $NewHostKey.trust_anchor 'xserver_server_panel' 'HOST_KEY_TRUST_ANCHOR_MISMATCH'
Assert-Equal $NewHostKey.host $NewEndpoint.host 'HOST_KEY_HOST_MISMATCH'
Assert-Equal ([int] $NewHostKey.port) 10022 'HOST_KEY_PORT_MISMATCH'
Assert-Equal $NewHostKey.expected_ip $NewEndpoint.ip 'HOST_KEY_IP_MISMATCH'
Assert-Equal $NewHostKey.algorithm 'ssh-ed25519' 'HOST_KEY_ALGORITHM_MISMATCH'
Assert-Equal $NewHostKey.panel_fingerprint $NewEndpoint.host_key_fingerprint 'PANEL_HOST_KEY_MISMATCH'
Assert-Equal $NewHostKey.observed_fingerprint $NewEndpoint.host_key_fingerprint 'OBSERVED_HOST_KEY_MISMATCH'
Assert-Equal ([bool] $NewHostKey.fingerprint_match) $true 'HOST_KEY_FINGERPRINT_MISMATCH'
Assert-Equal ([bool] $NewHostKey.production_mutation) $false 'HOST_KEY_MUTATION_DETECTED'
Assert-True ($NewHostKeyRecord.Contains([string] $NewEndpoint.host)) 'NEW_BINDING_RECORD_HOST_MISSING'
Assert-True ($NewHostKeyRecord.Contains([string] $NewEndpoint.host_key_fingerprint)) 'NEW_BINDING_RECORD_HOST_KEY_MISSING'

Assert-True ($LegacyEndpoint.host -ne $NewEndpoint.host) 'CROSS_SERVER_HOST_NOT_DISTINCT'
Assert-True ($LegacyEndpoint.host_key_fingerprint -ne $NewEndpoint.host_key_fingerprint) 'CROSS_SERVER_HOST_KEY_NOT_DISTINCT'
Assert-True ($LegacyEndpoint.login_uid -ne $NewEndpoint.login_uid) 'CROSS_SERVER_UID_NOT_DISTINCT'
Assert-True ($LegacyEndpoint.actual_home_sha256 -ne $NewEndpoint.actual_home_sha256) 'CROSS_SERVER_HOME_NOT_DISTINCT'

$Management = $Contract.management_plane_attestation
Assert-Equal $Management.authority 'Human' 'MANAGEMENT_AUTHORITY_MISMATCH'
Assert-Equal $Management.xserver_server_id $NewEndpoint.server_id 'MANAGEMENT_SERVER_ID_MISMATCH'
Assert-Equal $Management.subdomain 'app.company-os.jp' 'MANAGEMENT_SUBDOMAIN_MISMATCH'
Assert-Equal $Management.subdomain_state 'normal' 'MANAGEMENT_SUBDOMAIN_STATE_MISMATCH'
Assert-Equal $Management.file_manager_breadcrumb 'company-os.jp/public_html/app.company-os.jp' 'MANAGEMENT_BREADCRUMB_MISMATCH'
Assert-Equal ([bool] $Management.content_viewed) $false 'MANAGEMENT_CONTENT_BOUNDARY_MISMATCH'
Assert-Equal ([bool] $Management.production_mutation) $false 'MANAGEMENT_MUTATION_DETECTED'
Assert-Equal (@($Management.entries).Count) 3 'MANAGEMENT_ENTRY_COUNT_MISMATCH'

$EntryMap = @{}
foreach ($entry in @($Management.entries)) { $EntryMap[[string] $entry.name] = $entry }
foreach ($expected in @(
    @{ name='.user.ini'; permission='600'; classification='other_file' },
    @{ name='default_page.png'; permission='644'; classification='png_image' },
    @{ name='index.html'; permission='644'; classification='html_document' }
)) {
    Assert-True $EntryMap.ContainsKey($expected.name) 'MANAGEMENT_ENTRY_MISSING'
    Assert-Equal $EntryMap[$expected.name].permission $expected.permission 'MANAGEMENT_ENTRY_PERMISSION_MISMATCH'
    Assert-Equal $EntryMap[$expected.name].classification $expected.classification 'MANAGEMENT_ENTRY_CLASSIFICATION_MISMATCH'
}

$Required = $Contract.required_disposition
Assert-Equal $Required.remote_legacy_physical_separation 'unknown' 'REMOTE_OBSERVATION_DISPOSITION_MISMATCH'
Assert-Equal $Required.cross_server_identity 'PASS' 'CROSS_SERVER_DISPOSITION_MISMATCH'
Assert-Equal $Required.effective_legacy_separation 'yes' 'EFFECTIVE_SEPARATION_DISPOSITION_MISMATCH'
Assert-Equal $Required.separation_basis 'cross_server_trusted_host_identity' 'SEPARATION_BASIS_MISMATCH'
Assert-Equal $Required.target_anchor_binding 'PASS' 'TARGET_ANCHOR_DISPOSITION_MISMATCH'
Assert-Equal $Required.VHOST_DOCUMENT_ROOT_BINDING 'PASS' 'VHOST_DISPOSITION_MISMATCH'
Assert-Equal $Required.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION 'PENDING_G5_PUBLIC_ENTRY_GATE' 'PUBLIC_ENTRY_DISPOSITION_MISMATCH'
Assert-Equal $Required.g5_c_posix_capability_rehearsal 'not_executed' 'G5C_DISPOSITION_MISMATCH'
Assert-Equal $Required.g6_ssl 'OPEN' 'G6_SSL_DISPOSITION_MISMATCH'

foreach ($input in $InputReceipts) {
    $path = Join-Path $Root ([string] $input.relative_path -replace '/', '\')
    Assert-Equal (Get-Sha256 $path) $input.sha256 'INPUT_EVIDENCE_CHANGED_DURING_RECONCILIATION'
}

if ($VerifyOnly) {
    Write-Output 'G5B_CROSS_SERVER_RECONCILIATION_VERIFY_ONLY=PASS'
    Write-Output "candidate=$Candidate"
    Write-Output 'production_connection_attempted=false'
    Write-Output 'production_mutation=false'
    Write-Output 'g5_c_posix_capability_rehearsal=not_executed'
    exit 0
}

$AuditRoot = Join-Path $Root 'storage\app\release-audit'
$OutputRoot = Join-Path $AuditRoot "production-g5b-cross-server-reconciliation-$Candidate"
if (Test-Path -LiteralPath $OutputRoot) { Stop-Reconciliation 'OUTPUT_ALREADY_EXISTS' }
New-Item -ItemType Directory -Path $OutputRoot | Out-Null

$Receipt = [ordered]@{
    schema_version = 1
    contract_id = $ContractId
    candidate = $Candidate
    generated_at_jst = Get-JstTimestamp
    status = 'PASS'
    disposition = 'G5-B RECONCILED FORMAL PASS / G5-C POSIX CAPABILITY REHEARSAL READY'
    operation = 'production_free_local_evidence_reconciliation'
    input_evidence = @($InputReceipts)
    original_observations = [ordered]@{
        legacy_server_remote_legacy_physical_separation = $Legacy.legacy_physical_separation
        new_target_remote_legacy_physical_separation = $NewTarget.legacy_physical_separation
        new_target_remote_target_anchor_state = $NewTarget.target_anchor_state
        original_evidence_modified = $false
    }
    reconciled_disposition = [ordered]@{
        cross_server_identity = $Required.cross_server_identity
        effective_legacy_separation = $Required.effective_legacy_separation
        separation_basis = $Required.separation_basis
        target_anchor_binding = $Required.target_anchor_binding
        VHOST_DOCUMENT_ROOT_BINDING = $Required.VHOST_DOCUMENT_ROOT_BINDING
    }
    cross_server_identity = [ordered]@{
        legacy_host = $LegacyEndpoint.host
        new_target_host = $NewEndpoint.host
        host_distinct = $true
        host_key_distinct = $true
        login_uid_distinct = $true
        actual_home_distinct = $true
        legacy_paths_on_legacy_server = 'present'
        legacy_paths_on_new_target = 'absent'
    }
    management_plane = [ordered]@{
        authority = $Management.authority
        attested_date_jst = $Management.attested_date_jst
        xserver_server_id = $Management.xserver_server_id
        subdomain = $Management.subdomain
        subdomain_state = $Management.subdomain_state
        file_manager_breadcrumb = $Management.file_manager_breadcrumb
        entries = @($Management.entries)
        content_viewed = $false
        production_mutation = $false
        binding_basis = 'xserver_management_plane_triangulation'
    }
    pending = [ordered]@{
        PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION = $Required.PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION
        g5_c_posix_capability_rehearsal = $Required.g5_c_posix_capability_rehearsal
        g6_ssl = $Required.g6_ssl
    }
    safety = [ordered]@{
        production_connection_attempted = $false
        ssh_connection_attempted = $false
        http_request_attempted = $false
        database_connection_attempted = $false
        production_mutation = $false
        filesystem_mutation = $false
        dns_or_ssl_change = $false
        existing_evidence_unchanged = $true
    }
    next_action = 'RETURN_TO_HUMAN_CHATGPT_G5C_GATE'
}

$ReceiptPath = Join-Path $OutputRoot 'g5b-reconciled-disposition.json'
Save-Json $ReceiptPath $Receipt
$ReceiptHash = Get-Sha256 $ReceiptPath

Write-Output 'G5B_CROSS_SERVER_RECONCILIATION=PASS'
Write-Output 'G5B_DISPOSITION=G5-B RECONCILED FORMAL PASS / G5-C POSIX CAPABILITY REHEARSAL READY'
Write-Output "receipt_sha256=$ReceiptHash"
Write-Output 'production_connection_attempted=false'
Write-Output 'production_mutation=false'
Write-Output 'g5_c_posix_capability_rehearsal=not_executed'
Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT_G5C_GATE'
