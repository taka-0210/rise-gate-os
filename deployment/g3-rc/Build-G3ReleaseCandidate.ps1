[CmdletBinding()]
param(
    [switch] $VerifyOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Candidate = '924af91188cc60d33ff87c91b94ecc1d539566e6'
$CandidateTree = '7d979ef6a854bce7943740044a3f0c4d942dc617'
$ReleaseCase = 'IR1-G3-RC'
$OutputSchema = 'company-os.ir1.g3-rc-freeze.evidence.v1'
$PendingMigrations = @(
    '2026_09_19_000001_add_scope_one_contract_to_ai_proposals',
    '2026_09_19_000001_add_scope_two_account_security',
    '2026_09_20_000002_add_scope_three_organization_foundation',
    '2026_09_20_000003_add_scope_four_staff_invitation',
    '2026_09_20_000004_add_scope_five_membership_lifecycle',
    '2026_09_20_000005_add_scope_six_owner_onboarding',
    '2026_09_21_000006_create_scope_seven_business_domains',
    '2026_09_21_000007_add_direction_and_display_order_to_business_domains',
    '2026_09_21_000008_add_product_organization_eligibility',
    '2026_09_24_000001_add_scope_eight_project_action_foundation',
    '2026_09_25_000001_add_scope_nine_action_execution_foundation'
)

function Stop-G3([string] $Code) {
    throw "G3_RC_FREEZE_STOP:$Code"
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory)] [string] $FilePath,
        [Parameter(Mandatory)] [string[]] $Arguments,
        [Parameter(Mandatory)] [string] $WorkingDirectory,
        [Parameter(Mandatory)] [string] $LogPath
    )

    Push-Location -LiteralPath $WorkingDirectory
    try {
        $previousErrorAction = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            & $FilePath @Arguments 2>&1 | Tee-Object -FilePath $LogPath
            $processExit = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousErrorAction
        }
        if ($processExit -ne 0) {
            Stop-G3 "PROCESS_FAILED_$([IO.Path]::GetFileNameWithoutExtension($FilePath).ToUpperInvariant())"
        }
    } finally {
        Pop-Location
    }
}

function Get-Sha256([string] $Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

$RepositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$SchemaManifestPath = Join-Path $PSScriptRoot 'ir1-schema-additions.json'
$Php = Join-Path $RepositoryRoot 'storage\app\release-audit\g3-toolchain\php-8.3.30-Win32-vs16-x64\php.exe'
$Composer = Join-Path $RepositoryRoot 'composer.phar'
$NodeRoot = Join-Path $RepositoryRoot 'storage\app\release-audit\g3-toolchain\node-v22.22.3-win-x64'
$Node = Join-Path $NodeRoot 'node.exe'
$Npm = Join-Path $NodeRoot 'npm.cmd'
$Bash = 'C:\Program Files\Git\bin\bash.exe'
$Tar = Join-Path $env:SystemRoot 'System32\tar.exe'
$OutputRoot = Join-Path $RepositoryRoot "storage\app\release-audit\g3-rc-freeze-$Candidate"

foreach ($required in @($SchemaManifestPath, $Php, $Composer, $Node, $Npm, $Bash, $Tar)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        Stop-G3 'LOCAL_TOOL_OR_CONTRACT_MISSING'
    }
}

$schema = Get-Content -Raw -LiteralPath $SchemaManifestPath | ConvertFrom-Json
if ($schema.source_commit -ne $Candidate -or
    [int] $schema.new_table_count -ne 31 -or
    [int] $schema.added_column_count -ne 73 -or
    @($schema.new_tables).Count -ne 31 -or
    @($schema.added_columns).Count -ne 73 -or
    @($schema.new_tables | Sort-Object -Unique).Count -ne 31 -or
    @($schema.added_columns | Sort-Object -Unique).Count -ne 73) {
    Stop-G3 'SCHEMA_ADDITION_MANIFEST_INVALID'
}

$resolvedCommit = (& git -C $RepositoryRoot rev-parse "$Candidate^{commit}").Trim()
$resolvedTree = (& git -C $RepositoryRoot show -s --format=%T $Candidate).Trim()
if ($LASTEXITCODE -ne 0 -or $resolvedCommit -ne $Candidate -or $resolvedTree -ne $CandidateTree) {
    Stop-G3 'CANDIDATE_IDENTITY_MISMATCH'
}

$phpVersion = (& $Php -r 'echo PHP_MAJOR_VERSION,chr(46),PHP_MINOR_VERSION;').Trim()
$nodeVersion = (& $Node --version).Trim()
if ($phpVersion -ne '8.3' -or $nodeVersion -ne 'v22.22.3') {
    Stop-G3 'LOCAL_TOOLCHAIN_VERSION_MISMATCH'
}
$phpModules = @(& $Php -m)
$requiredPhpModules = @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip')
foreach ($requiredPhpModule in $requiredPhpModules) {
    if ($phpModules -notcontains $requiredPhpModule) {
        Stop-G3 'LOCAL_TOOLCHAIN_EXTENSION_MISSING'
    }
}

if ($VerifyOnly) {
    Write-Output 'G3_RC_FREEZE_VERIFY_ONLY=PASS'
    Write-Output "candidate=$Candidate"
    Write-Output 'schema_additions=31_tables_73_columns'
    Write-Output 'production_connection_attempted=false'
    Write-Output 'production_mutation=false'
    exit 0
}

if (Test-Path -LiteralPath $OutputRoot) {
    Stop-G3 'OUTPUT_ROOT_ALREADY_EXISTS'
}
New-Item -ItemType Directory -Path $OutputRoot | Out-Null

$TempBase = Join-Path ([IO.Path]::GetTempPath()) "company-os-g3-$Candidate-$PID"
$Worktree = Join-Path $TempBase 'source'
$BuildOne = Join-Path $OutputRoot 'build-one'
$BuildTwo = Join-Path $OutputRoot 'build-two'
$Logs = Join-Path $OutputRoot 'logs'
New-Item -ItemType Directory -Path $TempBase, $BuildOne, $BuildTwo, $Logs | Out-Null
$worktreeAdded = $false
$originalPath = $env:Path

try {
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & git -C $RepositoryRoot worktree add --detach $Worktree $Candidate 2>&1 | Tee-Object -FilePath (Join-Path $Logs 'worktree.log')
        $worktreeExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    if ($worktreeExit -ne 0) { Stop-G3 'WORKTREE_CREATE_FAILED' }
    $worktreeAdded = $true

    if ((& git -C $Worktree rev-parse HEAD).Trim() -ne $Candidate) {
        Stop-G3 'WORKTREE_CANDIDATE_MISMATCH'
    }

    $trackedFiles = @(& git -c core.quotepath=false -C $Worktree ls-files)
    if ($LASTEXITCODE -ne 0 -or $trackedFiles.Count -eq 0) { Stop-G3 'SOURCE_FILE_ENUMERATION_FAILED' }
    $sourceEntries = foreach ($relative in ($trackedFiles | Sort-Object)) {
        $full = Join-Path $Worktree ($relative -replace '/', '\')
        if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Stop-G3 'TRACKED_SOURCE_FILE_MISSING' }
        [ordered]@{
            path = $relative
            bytes = (Get-Item -LiteralPath $full).Length
            sha256 = Get-Sha256 $full
        }
    }
    $sourceManifest = [ordered]@{
        schema_version = 1
        source_commit = $Candidate
        source_tree = $CandidateTree
        file_count = @($sourceEntries).Count
        files = @($sourceEntries)
    }
    $sourceManifestPath = Join-Path $OutputRoot 'source-manifest.json'
    $sourceManifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $sourceManifestPath -Encoding UTF8

    $migrationFiles = @(Get-ChildItem -LiteralPath (Join-Path $Worktree 'database\migrations') -Filter '*.php' -File | Sort-Object Name)
    if ($migrationFiles.Count -ne 94) { Stop-G3 'CANDIDATE_MIGRATION_COUNT_MISMATCH' }
    $migrationEntries = foreach ($file in $migrationFiles) {
        [ordered]@{ name = $file.BaseName; sha256 = Get-Sha256 $file.FullName }
    }
    $actualPending = @($migrationEntries | Select-Object -Last 11 | ForEach-Object { $_.name })
    if (@(Compare-Object -ReferenceObject $PendingMigrations -DifferenceObject $actualPending).Count -ne 0) {
        Stop-G3 'EXACT_PENDING_MIGRATION_SET_MISMATCH'
    }
    $migrationManifest = [ordered]@{
        schema_version = 1
        source_commit = $Candidate
        baseline_ledger_count = 83
        candidate_migration_count = 94
        exact_pending_count = 11
        exact_pending = $PendingMigrations
        migrations = @($migrationEntries)
    }
    $migrationManifestPath = Join-Path $OutputRoot 'migration-manifest.json'
    $migrationManifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $migrationManifestPath -Encoding UTF8
    Copy-Item -LiteralPath $SchemaManifestPath -Destination (Join-Path $OutputRoot 'schema-additions-manifest.json')

    $forbiddenPatterns = @(
        '^database/migrations/2026_09_2[6-9]_',
        '^database/migrations/2026_10_',
        '^app/Services/AiCommon/',
        '^app/Models/AiCommon',
        '^app/Http/Controllers/AiCommon',
        '^realtime-relay/'
    )
    $unexpected = @($trackedFiles | Where-Object {
        $path = $_
        @($forbiddenPatterns | Where-Object { $path -match $_ }).Count -gt 0
    })
    if ($unexpected.Count -ne 0) { Stop-G3 'POST_IR1_SCOPE_MIXED_IN' }

    $scopeManifest = [ordered]@{
        schema_version = 1
        source_commit = $Candidate
        release_case = $ReleaseCase
        included_product_scope = @('Closed Product through Scope 9', 'IR-1 Release Hardening')
        excluded_post_ir1_scope = @('Scope 10', 'Scope 11', 'S11 Companion Delta', 'CE realtime corrective')
        forbidden_path_match_count = 0
        post_ir1_scope_mixed_in = $false
    }
    $scopeManifestPath = Join-Path $OutputRoot 'product-scope-manifest.json'
    $scopeManifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $scopeManifestPath -Encoding UTF8

    $composerLock = Join-Path $Worktree 'composer.lock'
    $packageLock = Join-Path $Worktree 'package-lock.json'
    $dependencyManifest = [ordered]@{
        schema_version = 1
        source_commit = $Candidate
        composer_lock_sha256 = Get-Sha256 $composerLock
        package_lock_sha256 = Get-Sha256 $packageLock
        php = (& $Php -r 'echo PHP_VERSION;').Trim()
        node = $nodeVersion
    }
    $dependencyManifestPath = Join-Path $OutputRoot 'dependency-lock-manifest.json'
    $dependencyManifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $dependencyManifestPath -Encoding UTF8

    $env:Path = "$NodeRoot;$([IO.Path]::GetDirectoryName($Php));C:\Program Files\Git\usr\bin;$originalPath"
    Invoke-Checked $Php @($Composer, 'install', '--no-interaction', '--prefer-dist', '--no-progress') $Worktree (Join-Path $Logs 'composer-dev.log')
    Copy-Item -LiteralPath (Join-Path $Worktree '.env.example') -Destination (Join-Path $Worktree '.env')
    Invoke-Checked $Php @('artisan', 'key:generate', '--force') $Worktree (Join-Path $Logs 'key-generate.log')
    Invoke-Checked $Php @('artisan', 'test') $Worktree (Join-Path $Logs 'artisan-test.log')
    Invoke-Checked $Npm @('ci', '--no-audit', '--no-fund') $Worktree (Join-Path $Logs 'npm-ci.log')
    Invoke-Checked $Npm @('run', 'build') $Worktree (Join-Path $Logs 'npm-build-one.log')
    if (-not (Test-Path -LiteralPath (Join-Path $Worktree 'public\build\manifest.json') -PathType Leaf)) {
        Stop-G3 'VITE_MANIFEST_MISSING'
    }
    Invoke-Checked $Php @($Composer, 'install', '--no-dev', '--classmap-authoritative', '--no-interaction', '--prefer-dist', '--no-progress') $Worktree (Join-Path $Logs 'composer-production.log')
    Invoke-Checked $Bash @('deployment/build-release-artifact.sh', $Candidate, $BuildOne) $Worktree (Join-Path $Logs 'artifact-one.log')

    Remove-Item -LiteralPath (Join-Path $Worktree 'public\build') -Recurse -Force
    Invoke-Checked $Npm @('run', 'build') $Worktree (Join-Path $Logs 'npm-build-two.log')
    Invoke-Checked $Bash @('deployment/build-release-artifact.sh', $Candidate, $BuildTwo) $Worktree (Join-Path $Logs 'artifact-two.log')

    $artifactName = "rise-gate-os-$Candidate.tar.gz"
    $artifactOne = Join-Path $BuildOne $artifactName
    $artifactTwo = Join-Path $BuildTwo $artifactName
    $artifactOneHash = Get-Sha256 $artifactOne
    $artifactTwoHash = Get-Sha256 $artifactTwo
    if ($artifactOneHash -ne $artifactTwoHash) { Stop-G3 'DETERMINISTIC_BUILD_MISMATCH' }

    $finalArtifact = Join-Path $OutputRoot $artifactName
    Copy-Item -LiteralPath $artifactOne -Destination $finalArtifact
    "$artifactOneHash  $artifactName" | Set-Content -LiteralPath "$finalArtifact.sha256" -Encoding ascii

    $memberList = Join-Path $OutputRoot 'artifact-members.txt'
    Push-Location -LiteralPath $Worktree
    try {
        & $Tar -tzf $finalArtifact 2>&1 | Set-Content -LiteralPath $memberList -Encoding UTF8
        if ($LASTEXITCODE -ne 0) { Stop-G3 'ARTIFACT_MEMBER_ENUMERATION_FAILED' }
    } finally { Pop-Location }
    $members = @(Get-Content -LiteralPath $memberList)
    if ($members.Count -eq 0 -or @($members | Where-Object { $_ -match '(^|/)\.env$|\.sqlite3?$|^\./storage/' }).Count -ne 0) {
        Stop-G3 'ARTIFACT_RUNTIME_STATE_VIOLATION'
    }

    $testLog = Get-Content -Raw -LiteralPath (Join-Path $Logs 'artisan-test.log')
    $passed = 0; $skipped = 0; $assertions = 0
    if ($testLog -match 'Tests:\s+(?:(\d+) skipped,\s+)?(\d+) passed\s+\(([\d,]+) assertions\)') {
        $skipped = if ($Matches[1]) { [int] $Matches[1] } else { 0 }
        $passed = [int] $Matches[2]
        $assertions = [int] ($Matches[3] -replace ',', '')
    } else { Stop-G3 'TEST_RESULT_PARSE_FAILED' }
    if ($passed -lt 1) { Stop-G3 'TEST_PASS_COUNT_INVALID' }

    $evidence = [ordered]@{
        output_schema = $OutputSchema
        status = 'PASS'
        generated_at_jst = [DateTimeOffset]::Now.ToOffset([TimeSpan]::FromHours(9)).ToString('o')
        release_case = $ReleaseCase
        source_commit = $Candidate
        source_tree = $CandidateTree
        artifact = [ordered]@{
            file = $artifactName
            sha256 = $artifactOneHash
            bytes = (Get-Item -LiteralPath $finalArtifact).Length
            deterministic_rebuild = $true
            second_build_sha256 = $artifactTwoHash
            member_count = $members.Count
        }
        manifests = [ordered]@{
            source_sha256 = Get-Sha256 $sourceManifestPath
            migration_sha256 = Get-Sha256 $migrationManifestPath
            schema_additions_sha256 = Get-Sha256 (Join-Path $OutputRoot 'schema-additions-manifest.json')
            dependency_lock_sha256 = Get-Sha256 $dependencyManifestPath
            product_scope_sha256 = Get-Sha256 $scopeManifestPath
        }
        migrations = [ordered]@{
            repository_count = 94
            production_baseline_ledger_count = 83
            exact_pending_count = 11
            exact_pending = $PendingMigrations
        }
        schema_additions = [ordered]@{
            new_table_count = 31
            added_column_count = 73
            prior_reported_column_count_corrected = 72
        }
        verification = [ordered]@{
            php_version = $dependencyManifest.php
            node_version = $nodeVersion
            tests_passed = $passed
            tests_skipped = $skipped
            assertions = $assertions
            frontend_build = 'PASS'
            artifact_build = 'PASS'
            deterministic_build = 'PASS'
            post_ir1_scope_mixed_in = $false
            secret_or_runtime_state_in_artifact = $false
        }
        production = [ordered]@{
            connection_attempted = $false
            mutation = $false
            deploy = $false
            migration = $false
        }
        continuing_blockers = @(
            'usable_backup_unknown',
            'db_restore_readiness_blocker',
            'release_marker_application_binding_blocker',
            'env_permission_0604_hardening_blocker',
            'user_cron_unknown',
            'external_writer_enablement_unknown',
            'active_transaction_metadata_lock_unsupported',
            'database_application_collation_difference'
        )
    }
    $evidencePath = Join-Path $OutputRoot 'g3-rc-freeze-evidence.json'
    $evidence | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $evidencePath -Encoding UTF8
    Get-Sha256 $evidencePath | Set-Content -LiteralPath "$evidencePath.sha256" -Encoding ascii

    & git -C $Worktree diff --quiet
    $unstagedExit = $LASTEXITCODE
    & git -C $Worktree diff --cached --quiet
    $stagedExit = $LASTEXITCODE
    if ($unstagedExit -ne 0 -or $stagedExit -ne 0) {
        Stop-G3 'CANDIDATE_TRACKED_FILES_MUTATED'
    }

    Write-Output 'G3_RC_FREEZE=PASS'
    Write-Output "candidate=$Candidate"
    Write-Output "artifact_sha256=$artifactOneHash"
    Write-Output 'deterministic_rebuild=true'
    Write-Output "tests_passed=$passed"
    Write-Output "tests_skipped=$skipped"
    Write-Output 'post_ir1_scope_mixed_in=false'
    Write-Output 'production_connection_attempted=false'
    Write-Output 'production_mutation=false'
} finally {
    $env:Path = $originalPath
    if ($worktreeAdded -and (Test-Path -LiteralPath $Worktree)) {
        & git -C $RepositoryRoot worktree remove --force $Worktree | Out-Null
    }
    if (Test-Path -LiteralPath $TempBase) {
        Remove-Item -LiteralPath $TempBase -Recurse -Force
    }
}
