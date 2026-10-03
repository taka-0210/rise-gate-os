[CmdletBinding()]
param()

$ErrorActionPreference='Stop'
$mariaRoot=Join-Path $env:TEMP 'company-os-scope10-mariadb-10.11.19\mariadb-10.11.19-winx64'
$server=Join-Path $mariaRoot 'bin\mariadbd.exe'; $client=Join-Path $mariaRoot 'bin\mariadb.exe'; $admin=Join-Path $mariaRoot 'bin\mariadb-admin.exe'; $installer=Join-Path $mariaRoot 'bin\mariadb-install-db.exe'; $php='C:\xampp\php\php.exe'
$expectedServerSha256='a96d7b256e215ae8e0970249189d72abef44940b12fe2d0aa68cc2b6d3babcf0'
$port=13385; $portArgument='--port='+$port; $database='company_os_35a_verification'; $runRoot=Join-Path $env:TEMP ('company-os-35a-mariadb-'+[guid]::NewGuid().ToString('N')); $process=$null; $errorLog=$null
function Stop-Safe([string]$code){Write-Output '35A_MARIADB_VERIFICATION=STOP';Write-Output ('safe_error_code='+$code);Write-Output 'production_connection=false';Write-Output 'production_mutation=false';exit 1}
try {
 foreach($required in @($server,$client,$admin,$installer,$php)){if(-not(Test-Path -LiteralPath $required)){Stop-Safe 'LOCAL_DEPENDENCY_MISSING'}}
 if((Get-FileHash -LiteralPath $server -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedServerSha256){Stop-Safe 'MARIADB_BINARY_HASH_MISMATCH'}
 $listener=New-Object Net.Sockets.TcpClient;try{$listener.Connect('127.0.0.1',$port);Stop-Safe 'LOCAL_PORT_IN_USE'}catch{}finally{$listener.Dispose()}
 New-Item -ItemType Directory -Path $runRoot | Out-Null; $data=Join-Path $runRoot 'data';$nativePreference=$ErrorActionPreference;$ErrorActionPreference='Continue';& $installer ('--datadir='+$data) ('--port='+$port) --allow-remote-root-access --silent 2>$null|Out-Null;if($LASTEXITCODE -ne 0){Stop-Safe 'NATIVE_DATA_DIRECTORY_INITIALIZATION_FAILED'}
 $script:errorLog=Join-Path $runRoot 'mariadb.log';$arguments=@('--no-defaults',('--basedir='+$mariaRoot),('--datadir='+$data),'--bind-address=127.0.0.1',('--port='+$port),'--skip-grant-tables','--event-scheduler=OFF','--skip-log-bin','--skip-name-resolve',('--log-error='+$script:errorLog),'--console=0')
 $process=Start-Process -FilePath $server -ArgumentList $arguments -PassThru -WindowStyle Hidden;$ready=$false
 for($i=0;$i -lt 60;$i++){Start-Sleep -Milliseconds 250;& $admin --protocol=tcp --host=127.0.0.1 $portArgument --user=root ping 2>$null|Out-Null;if($LASTEXITCODE -eq 0){$ready=$true;break};if($process.HasExited){break}}
 if(-not $ready){Stop-Safe 'MARIADB_START_FAILED'};$createSql='CREATE DATABASE '+$database+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';& $client --protocol=tcp --host=127.0.0.1 $portArgument --user=root ('--execute='+$createSql) 2>$null;if($LASTEXITCODE -ne 0){Stop-Safe 'DATABASE_CREATE_FAILED'}
 $evidencePath=Join-Path $runRoot ('35a-mariadb-evidence-'+[guid]::NewGuid().ToString('N')+'.json');$env:APP_ENV='testing';$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';$env:DB_CONNECTION='mariadb';$env:DB_HOST='127.0.0.1';$env:DB_PORT=[string]$port;$env:DB_DATABASE=$database;$env:DB_USERNAME='root';$env:DB_PASSWORD='';$env:DB_CHARSET='utf8mb4';$env:DB_COLLATION='utf8mb4_unicode_ci';$env:ANNUAL_POLICY_MARIADB_APPROVED='1';$env:ANNUAL_POLICY_MARIADB_EVIDENCE_PATH=$evidencePath
 $ErrorActionPreference='Continue';$output=& $php (Join-Path $PSScriptRoot '..\..\tests\Support\annual_management_policy_mariadb.php') 2>$null;$exit=$LASTEXITCODE;$ErrorActionPreference=$nativePreference;if($exit -ne 0){@($output)|Where-Object{$_ -like '35A_*'}|ForEach-Object{Write-Output $_};Stop-Safe 'APPLICATION_VERIFICATION_FAILED'};if(-not(Test-Path -LiteralPath $evidencePath)){Write-Output ('terminal_marker_seen='+[bool](@($output)-contains '35A_TERMINAL_EVIDENCE_WRITTEN'));Write-Output ('safe_stdout_line_count='+@($output).Count);Stop-Safe 'TERMINAL_EVIDENCE_MISSING'};$result=Get-Content -LiteralPath $evidencePath -Raw|ConvertFrom-Json
 if($result.status -ne 'PASS' -or $result.engine_version -notlike '10.11.19*'){Write-Output ('observed_status='+[string]$result.status);Write-Output ('observed_engine_version='+[string]$result.engine_version);Stop-Safe 'EVIDENCE_CONTRACT_FAILED'}
 Write-Output '35A_MARIADB_VERIFICATION=PASS';Write-Output ('engine_version='+$result.engine_version);Write-Output ('charset='+$result.charset);Write-Output ('collation='+$result.collation);Write-Output 'production_connection=false';Write-Output 'production_mutation=false'
} finally {
 foreach($name in @('APP_ENV','APP_KEY','DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_CHARSET','DB_COLLATION','ANNUAL_POLICY_MARIADB_APPROVED','ANNUAL_POLICY_MARIADB_EVIDENCE_PATH')){Remove-Item ('Env:'+$name) -ErrorAction SilentlyContinue}
 if($null-ne $process -and -not $process.HasExited){& $admin --protocol=tcp --host=127.0.0.1 $portArgument --user=root shutdown 2>$null|Out-Null;Start-Sleep -Milliseconds 500;if(-not $process.HasExited){Stop-Process -Id $process.Id -Force}}
 $resolved=[IO.Path]::GetFullPath($runRoot);$temp=[IO.Path]::GetFullPath($env:TEMP);if(Test-Path -LiteralPath $resolved){if(-not $resolved.StartsWith($temp,[StringComparison]::OrdinalIgnoreCase)-or-not(Split-Path $resolved -Leaf).StartsWith('company-os-35a-mariadb-')){throw 'Unsafe cleanup target'};Remove-Item -LiteralPath $resolved -Recurse -Force}
}
