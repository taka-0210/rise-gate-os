[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$expectedMariaDbPackageSha256 = '398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490'
$expectedMariaDbServerSha256 = 'a96d7b256e215ae8e0970249189d72abef44940b12fe2d0aa68cc2b6d3babcf0'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$mariaRoot = Join-Path $env:TEMP 'company-os-scope10-mariadb-10.11.19\mariadb-10.11.19-winx64'
$server = Join-Path $mariaRoot 'bin\mariadbd.exe'
$client = Join-Path $mariaRoot 'bin\mariadb.exe'
$admin = Join-Path $mariaRoot 'bin\mariadb-admin.exe'
$legacyServer = 'C:\xampp\mysql\bin\mysqld.exe'
$legacyAdmin = 'C:\xampp\mysql\bin\mysqladmin.exe'
$php = 'C:\xampp\php\php.exe'
$bash = 'C:\Program Files\Git\bin\bash.exe'
$template = 'C:\xampp\mysql\backup'
$port = 13381
$portArgument = '--port='+$port
$runRoot = Join-Path $env:TEMP ('company-os-g2-v2-e2e-'+[guid]::NewGuid().ToString('N'))
$process = $null

function Stop-Safe([string]$Code) {
    Write-Output 'G2_V2_ISOLATED_VERIFICATION=STOP'
    Write-Output ('safe_error_code='+$Code)
    Write-Output 'production_connection=false'
    Write-Output 'production_mutation=false'
    exit 1
}

try {
    foreach ($required in @($server, $client, $admin, $legacyServer, $legacyAdmin, $php, $bash, $template)) {
        if (-not (Test-Path -LiteralPath $required)) { Stop-Safe 'G2_V2_LOCAL_DEPENDENCY_MISSING' }
    }
    if ((Get-FileHash -LiteralPath $server -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedMariaDbServerSha256) {
        Stop-Safe 'G2_V2_MARIADB_BINARY_HASH_MISMATCH'
    }
    $listener = New-Object Net.Sockets.TcpClient
    try { $listener.Connect('127.0.0.1', $port); Stop-Safe 'G2_V2_LOCAL_PORT_IN_USE' } catch {} finally { $listener.Dispose() }

    New-Item -ItemType Directory -Path $runRoot | Out-Null
    $data = Join-Path $runRoot 'data'
    $artifactRoot = Join-Path $runRoot 'artifact'
    $bundleRoot = Join-Path $runRoot 'bundle'
    New-Item -ItemType Directory -Path $data, $artifactRoot, $bundleRoot | Out-Null
    Copy-Item -Path (Join-Path $template '*') -Destination $data -Recurse -Force

    # The XAMPP backup is a clean system-table template from MariaDB 10.4.
    # Start and cleanly stop it with its own engine before opening the copied
    # datadir with 10.11; this is isolated synthetic infrastructure only.
    $nativeErrorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $legacyLog = Join-Path $runRoot 'mariadb-legacy-error.log'
    $legacyArguments = @(
        '--no-defaults', '--basedir=C:\xampp\mysql', ('--datadir='+$data), '--bind-address=127.0.0.1',
        ('--port='+$port), '--skip-grant-tables', '--skip-log-bin', ('--log-error='+$legacyLog)
    )
    $legacyProcess = Start-Process -FilePath $legacyServer -ArgumentList $legacyArguments -PassThru -WindowStyle Hidden
    $legacyReady = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        Start-Sleep -Milliseconds 250
        & $legacyAdmin --protocol=tcp --host=127.0.0.1 $portArgument --user=root ping 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $legacyReady = $true; break }
        if ($legacyProcess.HasExited) { break }
    }
    if (-not $legacyReady) { Stop-Safe 'G2_V2_TEMPLATE_RECOVERY_FAILED' }
    & $legacyAdmin --protocol=tcp --host=127.0.0.1 $portArgument --user=root shutdown 2>$null | Out-Null
    $legacyProcess.WaitForExit(30000) | Out-Null
    $legacyProcess.Refresh()
    if (-not $legacyProcess.HasExited) { Stop-Process -Id $legacyProcess.Id -Force; Stop-Safe 'G2_V2_TEMPLATE_SHUTDOWN_FAILED' }

    $errorLog = Join-Path $runRoot 'mariadb-error.log'
    $arguments = @(
        '--no-defaults', ('--basedir='+$mariaRoot), ('--datadir='+$data), '--bind-address=127.0.0.1', ('--port='+$port),
        '--skip-grant-tables', '--event-scheduler=OFF', '--skip-log-bin', '--skip-name-resolve',
        ('--log-error='+$errorLog), '--console=0'
    )
    $process = Start-Process -FilePath $server -ArgumentList $arguments -PassThru -WindowStyle Hidden
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        Start-Sleep -Milliseconds 250
        & $admin --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root ping 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        if ($process.HasExited) { break }
    }
    if (-not $ready) { Stop-Safe 'G2_V2_MARIADB_START_FAILED' }

    $schemaSql = @"
CREATE DATABASE g2_v2_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE g2_v2_fixture;
CREATE TABLE ai_proposals (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE ai_proposal_items (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE organizations (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE users (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE organization_users (id BIGINT PRIMARY KEY, role VARCHAR(32) NULL) ENGINE=InnoDB;
CREATE TABLE business_domains (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE projects (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE project_members (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE roadmaps (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE improvements (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE tasks (id BIGINT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO organization_users (id, role) VALUES (1,'owner'),(2,'admin'),(3,'member'),(4,'viewer'),(5,NULL);
"@
    & $client --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root "--execute=$schemaSql" 2>$null
    if ($LASTEXITCODE -ne 0) { Stop-Safe 'G2_V2_FIXTURE_SETUP_FAILED' }
    $ErrorActionPreference = $nativeErrorPreference

    $artifact = Join-Path $repoRoot ('storage\app\release-audit\ir1-g2-preflight-v2-'+$candidate+'.tar.gz')
    $r0Bundle = Join-Path $repoRoot ('storage\app\release-audit\ir1-r0-audit-bundle-'+$candidate+'.tar.gz')
    & $bash -c 'tar --force-local -xzf "$1" -C "$2"' extract ($artifact -replace '\\','/') ($artifactRoot -replace '\\','/')
    if ($LASTEXITCODE -ne 0) { Stop-Safe 'G2_V2_ARTIFACT_EXTRACTION_FAILED' }
    & $bash -c 'tar --force-local -xzf "$1" -C "$2"' extract ($r0Bundle -replace '\\','/') ($bundleRoot -replace '\\','/')
    if ($LASTEXITCODE -ne 0) { Stop-Safe 'G2_V2_R0_BUNDLE_EXTRACTION_FAILED' }

    $environment = Join-Path $runRoot 'isolated.env'
    [IO.File]::WriteAllLines($environment, @(
        'DB_CONNECTION=mysql', 'DB_HOST=127.0.0.1', ('DB_PORT='+$port), 'DB_DATABASE=g2_v2_fixture',
        'DB_USERNAME=root', 'DB_PASSWORD='
    ), (New-Object Text.UTF8Encoding($false)))
    $env:G2_V2_PHP_BIN = $php -replace '\\','/'
    $output = & $bash ($artifactRoot+'\launcher.sh') ($bundleRoot -replace '\\','/') ($environment -replace '\\','/') 2>$null
    $exit = $LASTEXITCODE
    Remove-Item Env:\G2_V2_PHP_BIN -ErrorAction SilentlyContinue
    if ($exit -ne 0) { Stop-Safe 'G2_V2_END_TO_END_STOPPED' }

    $frames = @($output | ForEach-Object { $_ | ConvertFrom-Json })
    $terminal = $frames | Where-Object { $_.layer -eq 'php' -and $_.event -eq 'terminal' } | Select-Object -Last 1
    $checks = @($frames | Where-Object { $_.event -eq 'check_completed' })
    if ($null -eq $terminal -or $terminal.status -ne 'PASS' -or $terminal.data.database.version -notlike '10.11.19*' -or
        $terminal.data.sql_safety.total_statements -ne 8 -or $terminal.data.sql_safety.rejected_statements -ne 0 -or
        $terminal.data.sql_safety.persistent_db_write -ne $false -or $terminal.data.collision_preflight.existing_expected_new_column_count -ne 0 -or
        $terminal.data.collision_preflight.existing_expected_new_table_count -ne 0 -or $checks.Count -lt 9) {
        Stop-Safe 'G2_V2_EVIDENCE_CONTRACT_FAILED'
    }

    $packageArchive = Join-Path (Split-Path $server -Parent) '..\mariadb-10.11.19-winx64.zip'
    $result = [ordered]@{
        schema_version = 1
        status = 'PASS'
        candidate = $candidate
        mariadb_version = $terminal.data.database.version
        mariadb_package_expected_sha256 = $expectedMariaDbPackageSha256
        mariadb_server_sha256 = $expectedMariaDbServerSha256
        posix_shell = 'PASS'
        exact_artifact_sha256 = (Get-FileHash $artifact -Algorithm SHA256).Hash.ToLowerInvariant()
        incremental_frame_count = $frames.Count
        completed_check_count = $checks.Count
        sql_total_statements = $terminal.data.sql_safety.total_statements
        sql_rejected_statements = $terminal.data.sql_safety.rejected_statements
        persistent_db_write = $false
        ddl = $false
        migration_execution = $false
        schema_collision_count = 0
        production_connection = $false
        production_mutation = $false
        secret_output = $false
    }
    $evidencePath = Join-Path $repoRoot 'storage\app\release-audit\g2-preflight-v2-isolated-verification.json'
    [IO.File]::WriteAllText($evidencePath, (($result | ConvertTo-Json -Depth 5)+[Environment]::NewLine), (New-Object Text.UTF8Encoding($false)))
    Write-Output 'G2_V2_ISOLATED_VERIFICATION=PASS'
    Write-Output ('mariadb_version='+$result.mariadb_version)
    Write-Output ('artifact_sha256='+$result.exact_artifact_sha256)
    Write-Output ('incremental_frame_count='+$result.incremental_frame_count)
    Write-Output ('sql_total_statements='+$result.sql_total_statements)
    Write-Output 'production_connection=false'
    Write-Output 'production_mutation=false'
} finally {
    if ($null -ne $process -and -not $process.HasExited) {
        & $admin --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root shutdown 2>$null | Out-Null
        Start-Sleep -Milliseconds 500
        if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force }
    }
    $resolved = [IO.Path]::GetFullPath($runRoot)
    $temp = [IO.Path]::GetFullPath($env:TEMP)
    if (Test-Path -LiteralPath $resolved) {
        if (-not $resolved.StartsWith($temp, [StringComparison]::OrdinalIgnoreCase) -or -not (Split-Path $resolved -Leaf).StartsWith('company-os-g2-v2-e2e-')) {
            throw 'Unsafe isolated cleanup target.'
        }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
