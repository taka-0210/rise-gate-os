[CmdletBinding()]
param(
    [switch] $VerifyOnly,
    [switch] $VerifyLocalPreconditionsOnly,
    [switch] $Corrective1,
    [switch] $Corrective2,
    [switch] $Corrective3,
    [switch] $Corrective4,
    [switch] $Corrective5
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
$script:Corrective2HelperSha256 = '1b086b5c9414c469361382b4d76fb0d7475bef5f649833609b02d8a6e4edb56a'
$script:Corrective2PreflightPhpSha256 = '76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c'
$script:Corrective2AttemptStateSha256 = 'd2223bc7edb8db419fe275e086b0ba1589090e7873c8b279b1166dc75f871068'
$script:Corrective3HelperSha256 = 'c9c873929fb8533118d428751aa4987aa72921e3dabe45175a5c53158b34abdc'
$script:Corrective3PreflightPhpSha256 = '76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c'
$script:Corrective3AttemptStateSha256 = '83469c014b729870a9331894a8fd8eef25994103fa9246357afab077f0a61b70'
$script:RuntimeDiagnosticHelperSha256 = '77a4720ec377afaee740f74708eb76b46e598cf4416118b0435c28413f349882'
$script:RuntimeDiagnosticStateSha256 = 'af10dfc9166c63e200e340f92409648c56ef7ece449f18675826d9aa61441501'
$script:RuntimeDiagnosticEvidenceSha256 = 'dfb4629417fce372a2678b5554bf72809b80ab5c9559660d1d15198e405d748d'
$script:Corrective4HelperSha256 = '734a386f6f7a48bc3653b81b65a4c6692e904a381bc9bd9af986012ef06155a8'
$script:Corrective4PreflightPhpSha256 = '76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c'
$script:Corrective4AttemptStateSha256 = 'e81e8f59a2e8b8bd1fcd082ec20ffd24949535cca6dcba70498eedfa40d571a8'
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

function Assert-NativeArguments {
    param([Parameter(Mandatory = $true)][string[]] $Arguments)

    foreach ($argument in $Arguments) {
        if ([string]::IsNullOrWhiteSpace($argument) -or $argument -match '\s') {
            Stop-G2 'G2_NATIVE_ARGUMENT_REJECTED'
        }
    }
}

function Invoke-Utf8CapturedProcess {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [Parameter(Mandatory = $true)][string[]] $Arguments,
        [AllowEmptyString()][string] $StandardInput
    )

    Assert-NativeArguments -Arguments $Arguments
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
        if (-not $process.Start()) { Stop-G2 'G2_NATIVE_PROCESS_START_FAILED' }
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

function Get-SshArguments {
    param([Parameter(Mandatory = $true)][string] $KnownHostsPath)

    $arguments = @(
        '-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0',
        '-o','ConnectionAttempts=1','-o','ClearAllForwardings=yes','-o','ForwardAgent=no','-o','PermitLocalCommand=no',
        '-o',('UserKnownHostsFile=' + $KnownHostsPath),$script:SshAlias,'bash','-s'
    )
    Assert-NativeArguments -Arguments $arguments
    return $arguments
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

function Assert-Corrective2AttemptForCorrective3 {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $corrective2Root = Join-Path $RepositoryRoot ('storage\app\release-audit\production-g2-migration-preflight-corrective-2-' + $script:Candidate)
    $corrective2StatePath = Join-Path $corrective2Root 'execution-state.json'
    Assert-EvidenceFile $corrective2StatePath $script:Corrective2AttemptStateSha256 'G2_CORRECTIVE2_ATTEMPT_MISSING' 'G2_CORRECTIVE2_ATTEMPT_HASH_MISMATCH'
    try { $corrective2 = Get-Content -Raw -LiteralPath $corrective2StatePath | ConvertFrom-Json }
    catch { Stop-G2 'G2_CORRECTIVE2_ATTEMPT_INVALID' }

    if ($corrective2.execution_generation -ne 'corrective-2' -or
        $corrective2.status -ne 'STOP' -or
        $corrective2.candidate -ne $script:Candidate -or
        $corrective2.helper_sha256 -ne $script:Corrective2HelperSha256 -or
        $corrective2.preflight_php_sha256 -ne $script:Corrective2PreflightPhpSha256 -or
        $corrective2.safe_error_code -ne 'UNEXPECTED_LOCAL_FAILURE' -or
        $corrective2.failure_stage -ne 'PRODUCTION_READ_ONLY_G2_PREFLIGHT' -or
        $corrective2.production_connection_attempted -ne $true -or
        $corrective2.production_mutation -ne $false -or
        $corrective2.retry_performed -ne $false -or
        $corrective2.remote_exit_code -ne 1 -or
        $corrective2.stderr_bytes -ne 808 -or
        $corrective2.remote_stdout_contract_status -ne 'not_inspected' -or
        $null -ne $corrective2.remote_safe_error_code) {
        Stop-G2 'G2_CORRECTIVE2_ATTEMPT_CONTRACT_MISMATCH'
    }
}

function Assert-Corrective3AttemptForCorrective4 {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $corrective3Root = Join-Path $RepositoryRoot ('storage\app\release-audit\production-g2-migration-preflight-corrective-3-' + $script:Candidate)
    $corrective3StatePath = Join-Path $corrective3Root 'execution-state.json'
    Assert-EvidenceFile $corrective3StatePath $script:Corrective3AttemptStateSha256 'G2_CORRECTIVE3_ATTEMPT_MISSING' 'G2_CORRECTIVE3_ATTEMPT_HASH_MISMATCH'
    try { $corrective3 = Get-Content -Raw -LiteralPath $corrective3StatePath | ConvertFrom-Json }
    catch { Stop-G2 'G2_CORRECTIVE3_ATTEMPT_INVALID' }

    if ($corrective3.execution_generation -ne 'corrective-3' -or
        $corrective3.status -ne 'STOP' -or
        $corrective3.candidate -ne $script:Candidate -or
        $corrective3.helper_sha256 -ne $script:Corrective3HelperSha256 -or
        $corrective3.preflight_php_sha256 -ne $script:Corrective3PreflightPhpSha256 -or
        $corrective3.safe_error_code -ne 'UNEXPECTED_LOCAL_FAILURE' -or
        $corrective3.failure_stage -ne 'PRODUCTION_READ_ONLY_G2_PREFLIGHT' -or
        $corrective3.production_connection_attempted -ne $true -or
        $corrective3.production_mutation -ne $false -or
        $corrective3.retry_performed -ne $false -or
        $corrective3.remote_exit_code -ne 1 -or
        $corrective3.stdout_bytes -ne 0 -or
        $corrective3.stderr_bytes -ne 808 -or
        $corrective3.local_processing_substage -ne 'remote_stdout_validation') {
        Stop-G2 'G2_CORRECTIVE3_ATTEMPT_CONTRACT_MISMATCH'
    }
}

function Assert-RuntimeDiagnosticPassForCorrective4 {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $diagnosticRoot = Join-Path $RepositoryRoot ('storage\app\release-audit\production-g2-remote-runtime-diagnostic-' + $script:Candidate)
    $diagnosticStatePath = Join-Path $diagnosticRoot 'execution-state.json'
    $diagnosticEvidencePath = Join-Path $diagnosticRoot 'runtime-diagnostic-evidence.json'
    Assert-EvidenceFile $diagnosticStatePath $script:RuntimeDiagnosticStateSha256 'G2_RUNTIME_DIAGNOSTIC_STATE_MISSING' 'G2_RUNTIME_DIAGNOSTIC_STATE_HASH_MISMATCH'
    Assert-EvidenceFile $diagnosticEvidencePath $script:RuntimeDiagnosticEvidenceSha256 'G2_RUNTIME_DIAGNOSTIC_EVIDENCE_MISSING' 'G2_RUNTIME_DIAGNOSTIC_EVIDENCE_HASH_MISMATCH'
    try {
        $state = Get-Content -Raw -LiteralPath $diagnosticStatePath | ConvertFrom-Json
        $evidence = Get-Content -Raw -LiteralPath $diagnosticEvidencePath | ConvertFrom-Json
    }
    catch { Stop-G2 'G2_RUNTIME_DIAGNOSTIC_EVIDENCE_INVALID' }

    if ($state.status -ne 'PASS' -or
        $state.candidate -ne $script:Candidate -or
        $state.helper_sha256 -ne $script:RuntimeDiagnosticHelperSha256 -or
        $state.production_connection_attempted -ne $true -or
        $state.production_mutation -ne $false -or
        $state.retry_performed -ne $false -or
        $state.remote_exit_code -ne 0 -or
        $state.stderr_bytes -ne 0 -or
        $state.stdout_contract_status -ne 'safe_pass' -or
        $evidence.status -ne 'PASS' -or
        $evidence.audit_mode -ne 'read-only-runtime-diagnostic' -or
        $evidence.production_change_scope -ne 'none_read_only_runtime_diagnostic' -or
        $evidence.secret_output -ne $false -or
        $evidence.evidence.ssh_remote_shell -ne $true -or
        $evidence.evidence.php_cli_discovery -ne $true -or
        $evidence.evidence.php_interpreter_start -ne $true -or
        $evidence.evidence.php_stdin_execution -ne $true -or
        $evidence.evidence.fixed_stdout -ne $true -or
        $evidence.evidence.exit_code_capture -ne $true) {
        Stop-G2 'G2_RUNTIME_DIAGNOSTIC_PASS_CONTRACT_MISMATCH'
    }
}

function Assert-Corrective4AttemptForCorrective5 {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $corrective4Root = Join-Path $RepositoryRoot ('storage\app\release-audit\production-g2-migration-preflight-corrective-4-' + $script:Candidate)
    $corrective4StatePath = Join-Path $corrective4Root 'execution-state.json'
    Assert-EvidenceFile $corrective4StatePath $script:Corrective4AttemptStateSha256 'G2_CORRECTIVE4_ATTEMPT_MISSING' 'G2_CORRECTIVE4_ATTEMPT_HASH_MISMATCH'
    try { $corrective4 = Get-Content -Raw -LiteralPath $corrective4StatePath | ConvertFrom-Json }
    catch { Stop-G2 'G2_CORRECTIVE4_ATTEMPT_INVALID' }

    if ($corrective4.execution_generation -ne 'corrective-4' -or
        $corrective4.status -ne 'STOP' -or
        $corrective4.candidate -ne $script:Candidate -or
        $corrective4.helper_sha256 -ne $script:Corrective4HelperSha256 -or
        $corrective4.preflight_php_sha256 -ne $script:Corrective4PreflightPhpSha256 -or
        $corrective4.safe_error_code -ne 'G2_NATIVE_ARGUMENT_REJECTED' -or
        $corrective4.failure_stage -ne 'PRODUCTION_READ_ONLY_G2_PREFLIGHT' -or
        $corrective4.production_connection_attempted -ne $true -or
        $corrective4.production_mutation -ne $false -or
        $corrective4.retry_performed -ne $false -or
        $null -ne $corrective4.remote_exit_code -or
        $corrective4.stdout_bytes -ne 0 -or
        $corrective4.stderr_bytes -ne 27 -or
        $corrective4.local_processing_substage -ne 'remote_process_execution') {
        Stop-G2 'G2_CORRECTIVE4_ATTEMPT_CONTRACT_MISMATCH'
    }
}

function Get-LocalPreconditions {
    param([Parameter(Mandatory = $true)][string] $RepositoryRoot)

    $script:FailureStage = 'LOCAL_EVIDENCE_BINDING'
    Assert-LocalEvidenceContract -RepositoryRoot $RepositoryRoot
    if (@(@($Corrective1, $Corrective2, $Corrective3, $Corrective4, $Corrective5) | Where-Object { $_ }).Count -gt 1) {
        Stop-G2 'G2_CORRECTIVE_GENERATION_AMBIGUOUS'
    }
    if ($Corrective1) {
        Assert-OriginalAttemptForCorrective -RepositoryRoot $RepositoryRoot
    }
    if ($Corrective2) {
        Assert-OriginalAttemptForCorrective -RepositoryRoot $RepositoryRoot
        Assert-Corrective1AttemptForCorrective2 -RepositoryRoot $RepositoryRoot
    }
    if ($Corrective3) {
        Assert-OriginalAttemptForCorrective -RepositoryRoot $RepositoryRoot
        Assert-Corrective1AttemptForCorrective2 -RepositoryRoot $RepositoryRoot
        Assert-Corrective2AttemptForCorrective3 -RepositoryRoot $RepositoryRoot
    }
    if ($Corrective4) {
        Assert-OriginalAttemptForCorrective -RepositoryRoot $RepositoryRoot
        Assert-Corrective1AttemptForCorrective2 -RepositoryRoot $RepositoryRoot
        Assert-Corrective2AttemptForCorrective3 -RepositoryRoot $RepositoryRoot
        Assert-Corrective3AttemptForCorrective4 -RepositoryRoot $RepositoryRoot
        Assert-RuntimeDiagnosticPassForCorrective4 -RepositoryRoot $RepositoryRoot
    }
    if ($Corrective5) {
        Assert-OriginalAttemptForCorrective -RepositoryRoot $RepositoryRoot
        Assert-Corrective1AttemptForCorrective2 -RepositoryRoot $RepositoryRoot
        Assert-Corrective2AttemptForCorrective3 -RepositoryRoot $RepositoryRoot
        Assert-Corrective3AttemptForCorrective4 -RepositoryRoot $RepositoryRoot
        Assert-RuntimeDiagnosticPassForCorrective4 -RepositoryRoot $RepositoryRoot
        Assert-Corrective4AttemptForCorrective5 -RepositoryRoot $RepositoryRoot
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
    $lf = [string][char]10
    $remote = ($prefix + $PhpSource).Replace(([string][char]13 + [char]10), $lf).Replace(([string][char]13), $lf)
    return $remote.TrimEnd([char]10) + $lf + $delimiter + $lf
}

function Test-SafePassEvidence {
    param([Parameter(Mandatory = $true)][string] $Json)

    try {
        $evidence = $Json | ConvertFrom-Json
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
    catch {
        return $false
    }
}

function Test-SafeFailureEvidence {
    param([Parameter(Mandatory = $true)][string] $Json)

    try {
        $evidence = $Json | ConvertFrom-Json
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
    catch {
        return $false
    }
}

function Assert-EvidenceValidatorRegression {
    $safeFailureFixture = [pscustomobject]@{
        output_schema_version = 1
        status = 'INCONCLUSIVE'
        audit_mode = 'read-only'
        evidence_completeness = 'incomplete'
        failure = [pscustomobject]@{ safe_error_code = 'G2_SYNTHETIC_STOP'; failure_stage = 'synthetic_preflight' }
        evidence = [pscustomobject]@{
            candidate = $script:Candidate
            partial_evidence = [pscustomobject]@{
                application_bootstrap = 'not_used'
                database_connection = 'not_attempted'
                last_completed_condition = 'none'
                completed_conditions = @()
            }
            sql_safety = [pscustomobject]@{
                allowed_statement_classes = @('SELECT')
                statement_counts = [pscustomobject]@{ SELECT = 0 }
                total_statements = 0
                rejected_statements = 0
                statement_limit = 24
                persistent_db_write = $false
                ddl = $false
                migration_execution = $false
            }
        }
        secret_output = $false
        raw_identifier_output = $false
        raw_exception_output = $false
        production_change_scope = 'none_read_only_g2_migration_preflight'
    } | ConvertTo-Json -Depth 12 -Compress

    if (-not (Test-SafeFailureEvidence -Json $safeFailureFixture)) {
        Stop-G2 'G2_SAFE_FAILURE_VALIDATOR_REGRESSION'
    }
    if (Test-SafePassEvidence -Json $safeFailureFixture) {
        Stop-G2 'G2_SAFE_FAILURE_MISCLASSIFIED'
    }
    foreach ($rejectedFixture in @('{}', '{status:PASS}', 'not-json')) {
        if ((Test-SafePassEvidence -Json $rejectedFixture) -or (Test-SafeFailureEvidence -Json $rejectedFixture)) {
            Stop-G2 'G2_MALFORMED_EVIDENCE_ACCEPTED'
        }
    }
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
        Assert-EvidenceValidatorRegression
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
        $lf = [string][char]10
        if ($remote.Contains([string][char]13) -or
            -not $remote.Contains('__G2_PHP_SOURCE_924AF911__' + $lf) -or
            -not $remote.EndsWith($lf)) {
            Stop-G2 'G2_SELF_TEST_REMOTE_SCRIPT_NOT_LF_ONLY'
        }
        $localPhp = Join-Path (Split-Path -Parent (Split-Path -Parent $repositoryRoot)) 'php\php.exe'
        if (-not (Test-Path -LiteralPath $localPhp -PathType Leaf)) { Stop-G2 'G2_LOCAL_PHP_FIXTURE_MISSING' }
        $localProgram = '<?php echo ''G2_PREFLIGHT_STDIN_OK'';'
        $localResult = Invoke-Utf8CapturedProcess -FilePath $localPhp -Arguments @('-n') -StandardInput $localProgram
        if ($localResult.ExitCode -ne 0 -or $localResult.Stdout -ne 'G2_PREFLIGHT_STDIN_OK' -or
            -not [string]::IsNullOrEmpty($localResult.Stderr)) {
            Stop-G2 'G2_UTF8_STDIN_TRANSPORT_REGRESSION'
        }
        $sshArgumentFixture = @(Get-SshArguments -KnownHostsPath $preconditions.KnownHostsPath)
        if ($sshArgumentFixture.Count -lt 2 -or
            $sshArgumentFixture[-2] -ne 'bash' -or
            $sshArgumentFixture[-1] -ne '-s' -or
            $sshArgumentFixture -contains 'bash -s') {
            Stop-G2 'G2_NATIVE_ARGUMENT_TOKENIZATION_REGRESSION'
        }
        Write-Output 'G2_HELPER_VERIFY=PASS'
        Write-Output ('candidate=' + $script:Candidate)
        Write-Output ('helper_sha256=' + (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant())
        Write-Output ('preflight_php_sha256=' + $script:PreflightPhpSha256)
        Write-Output 'evidence_validator_regression=PASS'
        Write-Output 'remote_script_lf_only=true'
        Write-Output 'utf8_byte_stream_transport=PASS'
        Write-Output 'native_argument_tokenization=PASS'
        if ($Corrective4 -or $Corrective5) { Write-Output 'runtime_diagnostic_pass_binding_verified=true' }
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

    $executionGeneration = if ($Corrective5) { 'corrective-5' } elseif ($Corrective4) { 'corrective-4' } elseif ($Corrective3) { 'corrective-3' } elseif ($Corrective2) { 'corrective-2' } elseif ($Corrective1) { 'corrective-1' } else { 'initial' }
    $rootName = if ($Corrective5) {
        'production-g2-migration-preflight-corrective-5-' + $script:Candidate
    }
    elseif ($Corrective4) {
        'production-g2-migration-preflight-corrective-4-' + $script:Candidate
    }
    elseif ($Corrective3) {
        'production-g2-migration-preflight-corrective-3-' + $script:Candidate
    }
    elseif ($Corrective2) {
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
        stdout_sha256 = $null
        stdout_bytes = 0
        stderr_sha256 = $null
        stderr_bytes = 0
        remote_safe_error_code = $null
        remote_failure_stage = $null
        remote_database_connection = 'unknown'
        remote_last_completed_condition = 'unknown'
        remote_sql_total_statements = $null
        remote_sql_rejected_statements = $null
        remote_stdout_contract_status = 'not_inspected'
        native_argument_contract = 'not_evaluated'
        local_processing_substage = 'attempt_initialized'
        local_exception_sha256 = $null
    }
    Save-G2State
    $script:AttemptStarted = $true

    $sshArguments = @(Get-SshArguments -KnownHostsPath $preconditions.KnownHostsPath)
    $script:State.native_argument_contract = 'validated'
    $script:State.local_processing_substage = 'native_arguments_validated'
    Save-G2State
    $phpSource = Get-Content -Raw -LiteralPath $preconditions.PhpScriptPath
    $remoteScript = Get-RemoteScript -PhpSource $phpSource

    $script:FailureStage = 'PRODUCTION_READ_ONLY_G2_PREFLIGHT'
    $script:ProductionConnectionAttempted = $true
    $script:State.production_connection_attempted = $true
    $script:State.local_processing_substage = 'remote_process_execution'
    Save-G2State
    $result = if ($Corrective4 -or $Corrective5) {
        Invoke-Utf8CapturedProcess -FilePath $preconditions.SshPath -Arguments $sshArguments -StandardInput $remoteScript
    }
    else {
        Invoke-CapturedProcess -FilePath $preconditions.SshPath -Arguments $sshArguments -StandardInput $remoteScript
    }
    $script:State.local_processing_substage = 'remote_result_captured'
    $script:State.remote_exit_code = $result.ExitCode
    $script:State.stdout_sha256 = if ([string]::IsNullOrEmpty($result.Stdout)) { $null } else { Get-Sha256Text $result.Stdout }
    $script:State.stdout_bytes = if ([string]::IsNullOrEmpty($result.Stdout)) { 0 } else { [Text.Encoding]::UTF8.GetByteCount($result.Stdout) }
    $script:State.stderr_sha256 = if ([string]::IsNullOrEmpty($result.Stderr)) { $null } else { Get-Sha256Text $result.Stderr }
    $script:State.stderr_bytes = if ([string]::IsNullOrEmpty($result.Stderr)) { 0 } else { [Text.Encoding]::UTF8.GetByteCount($result.Stderr) }
    Save-G2State
    $evidencePath = Join-Path $evidenceRoot 'g2-preflight-evidence.json'
    $script:State.local_processing_substage = 'remote_stdout_validation'
    Save-G2State
    $safePass = Test-SafePassEvidence -Json $result.Stdout
    $safeFailure = Test-SafeFailureEvidence -Json $result.Stdout
    if ($safePass -or $safeFailure) {
        $script:State.remote_stdout_contract_status = if ($safePass) { 'safe_pass' } else { 'safe_failure' }
        if ($safeFailure) {
            $remoteFailure = $result.Stdout | ConvertFrom-Json
            $script:State.remote_safe_error_code = $remoteFailure.failure.safe_error_code
            $script:State.remote_failure_stage = $remoteFailure.failure.failure_stage
            $script:State.remote_database_connection = $remoteFailure.evidence.partial_evidence.database_connection
            $script:State.remote_last_completed_condition = $remoteFailure.evidence.partial_evidence.last_completed_condition
            $script:State.remote_sql_total_statements = $remoteFailure.evidence.sql_safety.total_statements
            $script:State.remote_sql_rejected_statements = $remoteFailure.evidence.sql_safety.rejected_statements
        }
        Save-G2State
        $script:State.local_processing_substage = 'sanitized_evidence_write'
        Save-G2State
        [IO.File]::WriteAllText($evidencePath, $result.Stdout, [Text.UTF8Encoding]::new($false))
    }
    else {
        $script:State.remote_stdout_contract_status = 'rejected'
        Save-G2State
    }
    $script:State.local_processing_substage = 'remote_result_decision'
    Save-G2State
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
    $script:State.local_processing_substage = 'complete'
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
        $script:State.local_exception_sha256 = Get-Sha256Text $_.Exception.Message
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
