[CmdletBinding()]
param(
    [ValidateSet('Inspect','Rehearse')][string] $Step = 'Inspect',
    [switch] $VerifyOnly
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Candidate='924af91188cc60d33ff87c91b94ecc1d539566e6'
$PackageSha='f89a71cbfe453b2e9bd74d20fdf5e3bab9c2b98e28775ddf755922713ca8a232'
$SshAlias='company-os-production'
$IdentityFile='codex-company-os-production'
$HelperGeneration='g5-discovery-corrective-2'
$FailureStage='LOCAL_PRECONDITIONS'
$ConnectionAttempted=$false
$LocalProcessingSubstage='LOCAL_PRECONDITIONS'
$NativeProcessStarted=$false
$RemoteExitCode=$null
$Stdout=''
$Stderr=''
$State=$null
$StatePath=$null
$AttemptStarted=$false

function Stop-G5([string]$Code){throw [InvalidOperationException]::new($Code)}
function Hash-Text([string]$Value){
    $sha=[Security.Cryptography.SHA256]::Create()
    try{return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value)))).Replace('-','').ToLowerInvariant()}
    finally{$sha.Dispose()}
}
function Read-LfScript([string]$Path){
    $text=[IO.File]::ReadAllText($Path)
    $crlf=[string]::Concat([char]13,[char]10)
    $cr=[string][char]13
    $lf=[string][char]10
    $normalized=$text.Replace($crlf,$lf).Replace($cr,$lf)
    if($normalized.Contains([char]13)){Stop-G5'SCRIPT_LINE_ENDING_NORMALIZATION_FAILED'}
    return $normalized
}
function Get-SafeErrorCode([Exception]$Exception){
    $value=$Exception.Message
    if($value-match'^[A-Z][A-Z0-9_]{2,80}$'){return $value}
    return 'UNEXPECTED_LOCAL_FAILURE'
}
function Save-State{
    if($null-eq$script:State-or[string]::IsNullOrWhiteSpace($script:StatePath)){return}
    $json=$script:State|ConvertTo-Json -Depth 8
    [IO.File]::WriteAllText($script:StatePath,$json+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))
}
function Assert-NativeArguments([string[]]$Arguments){
    foreach($argument in $Arguments){
        if([string]::IsNullOrWhiteSpace($argument)-or$argument-match'\s'){Stop-G5'NATIVE_ARGUMENT_REJECTED'}
    }
}
function Invoke-Utf8CapturedProcess([string]$File,[string[]]$Arguments,[AllowNull()][string]$InputText){
    Assert-NativeArguments $Arguments
    $startInfo=[Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName=$File
    $startInfo.Arguments=$Arguments-join' '
    $startInfo.UseShellExecute=$false
    $startInfo.CreateNoWindow=$true
    $startInfo.RedirectStandardInput=$true
    $startInfo.RedirectStandardOutput=$true
    $startInfo.RedirectStandardError=$true
    $process=[Diagnostics.Process]::new()
    $process.StartInfo=$startInfo
    try{
        if(-not$process.Start()){Stop-G5'NATIVE_PROCESS_START_FAILED'}
        $script:NativeProcessStarted=$true
        $script:LocalProcessingSubstage='NATIVE_PROCESS_STARTED'
        if($null-ne$script:State){
            $script:State.native_process_started=$true
            $script:State.local_processing_substage=$script:LocalProcessingSubstage
            Save-State
        }
        if($null-ne$InputText){
            $bytes=[Text.UTF8Encoding]::new($false).GetBytes($InputText)
            $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length)
            $process.StandardInput.BaseStream.Flush()
        }
        $process.StandardInput.Close()
        $script:LocalProcessingSubstage='STANDARD_INPUT_CLOSED'
        if($null-ne$script:State){
            $script:State.local_processing_substage=$script:LocalProcessingSubstage
            Save-State
        }
        $stdout=$process.StandardOutput.ReadToEnd()
        $stderr=$process.StandardError.ReadToEnd()
        $process.WaitForExit()
        return [pscustomobject]@{ExitCode=$process.ExitCode;Stdout=$stdout;Stderr=$stderr}
    }finally{$process.Dispose()}
}
function New-AttemptState{
    return [pscustomobject]@{
        schema_version=1;candidate=$Candidate;step=$Step;helper_generation=$HelperGeneration
        status='ATTEMPT_STARTED';failure_stage=$null;safe_error_code=$null
        local_processing_substage='ATTEMPT_INITIALIZED';production_connection_attempted=$false
        production_mutation=$false;production_mutation_possible=($Step-eq'Rehearse')
        native_process_started=$false;remote_exit_code=$null
        stdout_sha256=$null;stdout_bytes=0;stderr_sha256=$null;stderr_bytes=0
        ssh_authentication='unknown';remote_shell='unknown';remote_discovery_contract='unknown';remote_safe_error_code=$null
        last_completed_check='none';raw_output_stored=$false;retry_performed=$false
        local_exception_type=$null;local_exception_sha256=$null
    }
}
function Parse-SafeOutput([string]$Value,[string]$Header){
    if([Text.Encoding]::UTF8.GetByteCount($Value)-gt 16384 -or $Value-match'/home/[A-Za-z0-9._-]+' -or
       $Value-match'(?i)(DB_PASSWORD|APP_KEY|API[_-]?KEY|PRIVATE KEY|BEARER|CREDENTIAL)'){Stop-G5'UNSAFE_REMOTE_OUTPUT'}
    $map=[ordered]@{}
    foreach($line in($Value-split'\r?\n'|Where-Object{$_-ne''})){
        if($line-notmatch'^([a-zA-Z0-9_]+)=([a-zA-Z0-9_.-]+)$'){Stop-G5'REMOTE_OUTPUT_CONTRACT_INVALID'}
        if($map.Contains($Matches[1])){Stop-G5'REMOTE_OUTPUT_DUPLICATE_KEY'}
        $map[$Matches[1]]=$Matches[2]
    }
    if(-not $map.Contains($Header)){Stop-G5'REMOTE_STATUS_MISSING'}
    return $map
}
function Save-Receipt([string]$Path,[object]$Value){
    $json=$Value|ConvertTo-Json -Depth 7
    [IO.File]::WriteAllText($Path+'.tmp',$json+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath($Path+'.tmp') -Destination $Path
}

try{
    $Root=(Resolve-Path(Join-Path $PSScriptRoot '..\..')).Path
    $PackageRoot=Join-Path $Root "storage\app\release-audit\g5-target-package-$Candidate"
    $Package=Join-Path $PackageRoot "company-os-g5-target-$Candidate.tar.gz"
    $Manifest=Join-Path $PackageRoot 'package-manifest.json'
    if(-not(Test-Path $Package -PathType Leaf)-or(Get-FileHash -Algorithm SHA256 $Package).Hash.ToLowerInvariant()-ne$PackageSha-or
       -not(Test-Path $Manifest -PathType Leaf)){Stop-G5'PACKAGE_INTEGRITY_FAILED'}
    $manifestObject=Get-Content -Raw $Manifest|ConvertFrom-Json
    foreach($file in @($manifestObject.files)){
        $source=Join-Path $PSScriptRoot([string]$file.path)
        if(-not(Test-Path $source -PathType Leaf)-or(Get-FileHash -Algorithm SHA256 $source).Hash.ToLowerInvariant()-ne[string]$file.sha256){
            Stop-G5'SOURCE_MANIFEST_MISMATCH'
        }
    }
    $ssh=Get-Command ssh.exe -ErrorAction SilentlyContinue;$keygen=Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue
    if($null-eq$ssh-or$null-eq$keygen){Stop-G5'OPENSSH_CLIENT_UNAVAILABLE'}
    $configResult=Invoke-Utf8CapturedProcess $ssh.Source @('-G',$SshAlias) $null
    if($configResult.ExitCode-ne0){Stop-G5'SSH_CONFIG_UNAVAILABLE'}
    $config=@{}
    foreach($line in($configResult.Stdout-split'\r?\n')){
        $parts=$line-split'\s+',2;if($parts.Count-eq2){$config[$parts[0].ToLowerInvariant()]=$parts[1]}
    }
    foreach($key in @('hostname','user','port','identityfile')){if(-not$config.ContainsKey($key)){Stop-G5'SSH_CONFIG_INCOMPLETE'}}
    if((Split-Path -Leaf $config.identityfile.Trim('"'))-ne$IdentityFile-or$config.identitiesonly-ne'yes'){Stop-G5'SSH_IDENTITY_CONTRACT_MISMATCH'}
    foreach($key in @('remotecommand','localcommand','proxycommand','proxyjump')){
        if($config.ContainsKey($key)-and$config[$key]-ne'none'){Stop-G5'SSH_AUTOMATIC_COMMAND_FORBIDDEN'}
    }
    $known=Join-Path([Environment]::GetFolderPath('UserProfile'))'.ssh\known_hosts'
    if(-not(Test-Path $known -PathType Leaf)){Stop-G5'KNOWN_HOSTS_MISSING'}
    $lookup="[$($config.hostname)]:$($config.port)"
    if((Invoke-Utf8CapturedProcess $keygen.Source @('-F',$lookup,'-f',$known) $null).ExitCode-ne0){Stop-G5'HOST_KEY_NOT_REGISTERED'}
    $FailureStage='LOCAL_REMOTE_SCRIPT_PREPARATION'
    $scriptName=if($Step-eq'Inspect'){'inspect-target-read-only.sh'}else{'rehearse-posix-capabilities.sh'}
    $confirm=if($Step-eq'Inspect'){'IR1-G5-READ-ONLY-INSPECTION'}else{'IR1-G5-POSIX-CAPABILITY-REHEARSAL'}
    $header=if($Step-eq'Inspect'){'G5_TARGET_INSPECTION'}else{'G5_POSIX_CAPABILITY_REHEARSAL'}
    $scriptText=Read-LfScript(Join-Path $PSScriptRoot $scriptName)
    if($VerifyOnly){
        $LocalProcessingSubstage='LOCAL_NATIVE_CAPTURE_SELF_TEST'
        $localPhp=Join-Path $Root 'storage\app\release-audit\g3-toolchain\php-8.3.30-Win32-vs16-x64\php.exe'
        if(-not(Test-Path -LiteralPath $localPhp -PathType Leaf)){Stop-G5'LOCAL_NATIVE_CAPTURE_FIXTURE_MISSING'}
        $fixture=Invoke-Utf8CapturedProcess $localPhp @('-n') '<?php echo "G5_NATIVE_CAPTURE=PASS\n";'
        $expectedFixture='G5_NATIVE_CAPTURE=PASS'+[char]10
        if($fixture.ExitCode-ne0-or$fixture.Stdout-ne$expectedFixture-or-not[string]::IsNullOrEmpty($fixture.Stderr)){
            Stop-G5'LOCAL_NATIVE_CAPTURE_SELF_TEST_FAILED'
        }
        $stateFixture=Join-Path([IO.Path]::GetTempPath())("g5-state-contract-$PID.json")
        $savedState=$State
        $savedStatePath=$StatePath
        try{
            $State=New-AttemptState
            $StatePath=$stateFixture
            Save-State
            $roundTrip=Get-Content -Raw -LiteralPath $stateFixture|ConvertFrom-Json
            if($roundTrip.helper_generation-ne$HelperGeneration-or$roundTrip.status-ne'ATTEMPT_STARTED'-or
               [bool]$roundTrip.production_connection_attempted-or[bool]$roundTrip.production_mutation){
                Stop-G5'LOCAL_STATE_CONTRACT_SELF_TEST_FAILED'
            }
        }finally{
            $State=$savedState
            $StatePath=$savedStatePath
            Remove-Item -LiteralPath $stateFixture -Force -ErrorAction SilentlyContinue
        }
        Write-Output 'G5_TARGET_HELPER_VERIFY_ONLY=PASS'; Write-Output "package_sha256=$PackageSha"
        Write-Output "helper_generation=$HelperGeneration"; Write-Output 'human_execution_path=validated_through_native_capture'
        Write-Output 'incremental_state_contract=PASS'
        Write-Output 'production_connection_attempted=false'; Write-Output 'production_mutation=false'; exit 0
    }

    $EvidenceRoot=Join-Path $Root "storage\app\release-audit\production-g5-target-$Candidate"
    if(-not(Test-Path $EvidenceRoot)){New-Item -ItemType Directory -Path $EvidenceRoot|Out-Null}
    $receiptName=if($Step-eq'Inspect'){'read-only-inspection.json'}else{'capability-rehearsal.json'}
    $receiptPath=Join-Path $EvidenceRoot $receiptName
    if(Test-Path $receiptPath){Stop-G5'STEP_RETRY_FORBIDDEN'}
    $stateName=if($Step-eq'Inspect'){'read-only-inspection-corrective-2-state.json'}else{'capability-rehearsal-corrective-2-state.json'}
    $StatePath=Join-Path $EvidenceRoot $stateName
    if(Test-Path $StatePath){Stop-G5'CORRECTIVE_ATTEMPT_ALREADY_RECORDED'}
    if($Step-eq'Rehearse'){
        $inspectionPath=Join-Path $EvidenceRoot'read-only-inspection.json'
        if(-not(Test-Path $inspectionPath -PathType Leaf)){Stop-G5'READ_ONLY_INSPECTION_REQUIRED'}
        $inspection=Get-Content -Raw $inspectionPath|ConvertFrom-Json
        if($inspection.status-ne'PASS'-or$inspection.evidence.mutation_readiness-ne'capability_rehearsal_eligible'){Stop-G5'CAPABILITY_REHEARSAL_NOT_ELIGIBLE'}
    }
    $arguments=@('-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0','-o','ConnectionAttempts=1',
        '-o','ConnectTimeout=10','-o','ClearAllForwardings=yes','-o','LogLevel=ERROR','-T',$SshAlias,'sh','-s','--',$confirm)
    Assert-NativeArguments $arguments
    $NativeProcessStarted=$false
    $State=New-AttemptState
    $AttemptStarted=$true
    Save-State
    $FailureStage=if($Step-eq'Inspect'){'PRODUCTION_READ_ONLY_INSPECTION'}else{'PRODUCTION_ISOLATED_CAPABILITY_REHEARSAL'}
    $ConnectionAttempted=$true
    $LocalProcessingSubstage='REMOTE_PROCESS_START'
    $State.production_connection_attempted=$true
    $State.failure_stage=$FailureStage
    $State.local_processing_substage=$LocalProcessingSubstage
    Save-State
    $result=Invoke-Utf8CapturedProcess $ssh.Source $arguments $scriptText
    $RemoteExitCode=$result.ExitCode
    $Stdout=$result.Stdout
    $Stderr=$result.Stderr
    $LocalProcessingSubstage='REMOTE_RESULT_CAPTURED'
    $State.remote_exit_code=$RemoteExitCode
    $State.stdout_sha256=if([string]::IsNullOrEmpty($Stdout)){$null}else{Hash-Text $Stdout}
    $State.stdout_bytes=[Text.Encoding]::UTF8.GetByteCount($Stdout)
    $State.stderr_sha256=if([string]::IsNullOrEmpty($Stderr)){$null}else{Hash-Text $Stderr}
    $State.stderr_bytes=[Text.Encoding]::UTF8.GetByteCount($Stderr)
    $State.local_processing_substage=$LocalProcessingSubstage
    Save-State
    $LocalProcessingSubstage='REMOTE_STDOUT_VALIDATION'
    $State.local_processing_substage=$LocalProcessingSubstage
    Save-State
    $parsed=Parse-SafeOutput $result.Stdout $header
    $State.ssh_authentication='established'
    $State.remote_shell='established'
    $State.remote_discovery_contract=if($parsed[$header]-eq'PASS'){'complete'}else{'reported_stop'}
    $State.last_completed_check='remote_contract_started'
    if($parsed.Contains('safe_error_code')){$State.remote_safe_error_code=$parsed['safe_error_code']}
    Save-State
    if($parsed[$header]-ne'PASS'){Stop-G5'REMOTE_REPORTED_STOP'}
    if($result.ExitCode-ne0-or-not[string]::IsNullOrWhiteSpace($result.Stderr)){Stop-G5'REMOTE_STEP_FAILED'}
    $State.last_completed_check='remote_contract_complete'
    $LocalProcessingSubstage='SANITIZED_RECEIPT_WRITE'
    $State.local_processing_substage=$LocalProcessingSubstage
    Save-State
    $receipt=[ordered]@{schema_version=1;candidate=$Candidate;step=$Step;status='PASS';package_sha256=$PackageSha;helper_generation=$HelperGeneration
        production_connection_attempted=$true;production_mutation=($Step-eq'Rehearse');native_process_started=$true
        remote_exit_code=$RemoteExitCode;stdout_sha256=Hash-Text $Stdout;stdout_bytes=$State.stdout_bytes
        stderr_sha256=Hash-Text $Stderr;stderr_bytes=$State.stderr_bytes;raw_output_stored=$false;evidence=$parsed}
    Save-Receipt $receiptPath $receipt
    $State.status='PASS'
    $State.local_processing_substage='COMPLETE'
    $State.safe_error_code=$null
    Save-State
    Write-Output "G5_TARGET_$($Step.ToUpperInvariant())=PASS"; Write-Output 'production_connection_attempted=true'
    Write-Output "production_mutation=$(($Step-eq'Rehearse').ToString().ToLowerInvariant())"
    Write-Output 'retry_available=false'; Write-Output 'secret_output=false'; Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
}catch{
    $safeCode=Get-SafeErrorCode $_.Exception
    if($AttemptStarted-and$null-ne$State){
        $State.status='STOP'
        $State.failure_stage=$FailureStage
        $State.safe_error_code=$safeCode
        $State.production_connection_attempted=$ConnectionAttempted
        $State.production_mutation=($Step-eq'Rehearse')
        $State.native_process_started=$NativeProcessStarted
        $State.remote_exit_code=$RemoteExitCode
        $State.local_processing_substage=$LocalProcessingSubstage
        $State.local_exception_type=$_.Exception.GetType().Name
        $State.local_exception_sha256=Hash-Text $_.Exception.Message
        Save-State
    }
    Write-Output "G5_TARGET_$($Step.ToUpperInvariant())=STOP"; Write-Output "safe_error_code=$safeCode"
    Write-Output "failure_stage=$FailureStage"; Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output "local_processing_substage=$LocalProcessingSubstage"; Write-Output "native_process_started=$($NativeProcessStarted.ToString().ToLowerInvariant())"
    Write-Output "production_mutation=$(if($Step-eq'Inspect'){'false'}else{'unknown_possible'})"
    Write-Output 'retry_performed=false'; Write-Output 'secret_output=false'; Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'; exit 1
}
