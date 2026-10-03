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
$FailureStage='LOCAL_PRECONDITIONS'
$ConnectionAttempted=$false

function Stop-G5([string]$Code){throw [InvalidOperationException]::new($Code)}
function Hash-Text([string]$Value){
    $sha=[Security.Cryptography.SHA256]::Create()
    try{return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value)))).Replace('-','').ToLowerInvariant()}
    finally{$sha.Dispose()}
}
function Invoke-Captured([string]$File,[string[]]$Arguments,[AllowNull()][string]$InputText){
    $stdout=[IO.Path]::GetTempFileName();$stderr=[IO.Path]::GetTempFileName();$old=$ErrorActionPreference
    try{
        $ErrorActionPreference='Continue'
        if($null -eq $InputText){& $File @Arguments 1> $stdout 2> $stderr}else{$InputText|& $File @Arguments 1> $stdout 2> $stderr}
        return [pscustomobject]@{ExitCode=$LASTEXITCODE;Stdout=[IO.File]::ReadAllText($stdout);Stderr=[IO.File]::ReadAllText($stderr)}
    }finally{$ErrorActionPreference=$old;Remove-Item -LiteralPath $stdout,$stderr -Force -ErrorAction SilentlyContinue}
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
    if(-not $map.Contains($Header)-or $map[$Header]-ne'PASS'){Stop-G5'REMOTE_STATUS_NOT_PASS'}
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
    $configResult=Invoke-Captured $ssh.Source @('-G',$SshAlias) $null
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
    if((Invoke-Captured $keygen.Source @('-F',$lookup,'-f',$known) $null).ExitCode-ne0){Stop-G5'HOST_KEY_NOT_REGISTERED'}
    if($VerifyOnly){
        Write-Output 'G5_TARGET_HELPER_VERIFY_ONLY=PASS'; Write-Output "package_sha256=$PackageSha"
        Write-Output 'production_connection_attempted=false'; Write-Output 'production_mutation=false'; exit 0
    }

    $EvidenceRoot=Join-Path $Root "storage\app\release-audit\production-g5-target-$Candidate"
    if(-not(Test-Path $EvidenceRoot)){New-Item -ItemType Directory -Path $EvidenceRoot|Out-Null}
    $receiptName=if($Step-eq'Inspect'){'read-only-inspection.json'}else{'capability-rehearsal.json'}
    $receiptPath=Join-Path $EvidenceRoot $receiptName
    if(Test-Path $receiptPath){Stop-G5'STEP_RETRY_FORBIDDEN'}
    if($Step-eq'Rehearse'){
        $inspectionPath=Join-Path $EvidenceRoot'read-only-inspection.json'
        if(-not(Test-Path $inspectionPath -PathType Leaf)){Stop-G5'READ_ONLY_INSPECTION_REQUIRED'}
        $inspection=Get-Content -Raw $inspectionPath|ConvertFrom-Json
        if($inspection.status-ne'PASS'-or$inspection.evidence.mutation_readiness-ne'capability_rehearsal_eligible'){Stop-G5'CAPABILITY_REHEARSAL_NOT_ELIGIBLE'}
    }
    $FailureStage=if($Step-eq'Inspect'){'PRODUCTION_READ_ONLY_INSPECTION'}else{'PRODUCTION_ISOLATED_CAPABILITY_REHEARSAL'}
    $scriptName=if($Step-eq'Inspect'){'inspect-target-read-only.sh'}else{'rehearse-posix-capabilities.sh'}
    $confirm=if($Step-eq'Inspect'){'IR1-G5-READ-ONLY-INSPECTION'}else{'IR1-G5-POSIX-CAPABILITY-REHEARSAL'}
    $scriptText=[IO.File]::ReadAllText((Join-Path $PSScriptRoot $scriptName)).Replace(([char]13+[char]10),[char]10)
    $arguments=@('-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','NumberOfPasswordPrompts=0','-o','ConnectionAttempts=1',
        '-o','ConnectTimeout=10','-o','ClearAllForwardings=yes','-o','LogLevel=ERROR','-T',$SshAlias,'sh','-s','--',$confirm)
    $ConnectionAttempted=$true;$result=Invoke-Captured $ssh.Source $arguments $scriptText
    $header=if($Step-eq'Inspect'){'G5_TARGET_INSPECTION'}else{'G5_POSIX_CAPABILITY_REHEARSAL'}
    $parsed=Parse-SafeOutput $result.Stdout $header
    if($result.ExitCode-ne0-or-not[string]::IsNullOrWhiteSpace($result.Stderr)){Stop-G5'REMOTE_STEP_FAILED'}
    $receipt=[ordered]@{schema_version=1;candidate=$Candidate;step=$Step;status='PASS';package_sha256=$PackageSha
        production_connection_attempted=$true;production_mutation=($Step-eq'Rehearse');stdout_sha256=Hash-Text $result.Stdout
        stderr_sha256=Hash-Text $result.Stderr;raw_output_stored=$false;evidence=$parsed}
    Save-Receipt $receiptPath $receipt
    Write-Output "G5_TARGET_$($Step.ToUpperInvariant())=PASS"; Write-Output 'production_connection_attempted=true'
    Write-Output "production_mutation=$(($Step-eq'Rehearse').ToString().ToLowerInvariant())"
    Write-Output 'retry_available=false'; Write-Output 'secret_output=false'; Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'
}catch{
    Write-Output "G5_TARGET_$($Step.ToUpperInvariant())=STOP"; Write-Output "safe_error_code=$($_.Exception.Message)"
    Write-Output "failure_stage=$FailureStage"; Write-Output "production_connection_attempted=$($ConnectionAttempted.ToString().ToLowerInvariant())"
    Write-Output 'retry_performed=false'; Write-Output 'secret_output=false'; Write-Output 'next_action=RETURN_TO_HUMAN_CHATGPT'; exit 1
}
