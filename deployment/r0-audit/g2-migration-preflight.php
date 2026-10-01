<?php

declare(strict_types=1);

use Dotenv\Dotenv;

const G2_OUTPUT_SCHEMA_VERSION = 1;
const G2_EXACT_CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';
const G2_SQL_LIMIT = 24;

$g2Progress = [
    'application_bootstrap' => 'not_used',
    'database_connection' => 'not_attempted',
    'last_completed_condition' => 'none',
    'completed_conditions' => [],
];
$g2SqlSafety = [
    'allowed_statement_classes' => ['SELECT'],
    'statement_counts' => ['SELECT' => 0],
    'total_statements' => 0,
    'rejected_statements' => 0,
    'statement_limit' => G2_SQL_LIMIT,
    'persistent_db_write' => false,
    'ddl' => false,
    'migration_execution' => false,
];

function g2Checkpoint(string $condition): void
{
    global $g2Progress;

    $g2Progress['last_completed_condition'] = $condition;
    $g2Progress['completed_conditions'][] = $condition;
}

/** @return never */
function g2Stop(string $safeErrorCode, string $failureStage): void
{
    global $g2Progress, $g2SqlSafety;

    fwrite(STDOUT, json_encode([
        'output_schema_version' => G2_OUTPUT_SCHEMA_VERSION,
        'status' => 'INCONCLUSIVE',
        'audit_mode' => 'read-only',
        'evidence_completeness' => 'incomplete',
        'failure' => [
            'safe_error_code' => $safeErrorCode,
            'failure_stage' => $failureStage,
        ],
        'evidence' => [
            'candidate' => G2_EXACT_CANDIDATE,
            'partial_evidence' => $g2Progress,
            'sql_safety' => $g2SqlSafety,
        ],
        'secret_output' => false,
        'raw_identifier_output' => false,
        'raw_exception_output' => false,
        'production_change_scope' => 'none_read_only_g2_migration_preflight',
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}

/**
 * Executes only fixed SELECT statements and records the statement count.
 *
 * @return list<array<string, mixed>>
 */
function g2Select(PDO $pdo, string $sql, array &$sqlSafety): array
{
    if (! preg_match('/^\s*SELECT\b/i', $sql)) {
        $sqlSafety['rejected_statements']++;
        g2Stop('G2_NON_SELECT_STATEMENT_REJECTED', 'sql_guard');
    }

    $sqlSafety['total_statements']++;
    $sqlSafety['statement_counts']['SELECT']++;
    if ($sqlSafety['total_statements'] > G2_SQL_LIMIT) {
        g2Stop('G2_SQL_LIMIT_EXCEEDED', 'sql_guard');
    }

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('G2_QUERY_FAILED');
    }

    /** @var list<array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
}

function g2RequiredEnvironment(string $name): string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if (! is_string($value) || trim($value) === '') {
        g2Stop('G2_DATABASE_CONFIG_UNAVAILABLE', 'database_configuration');
    }

    return $value;
}

$candidate = getenv('G2_CANDIDATE');
$environmentFile = getenv('IR1_R0_ENV_FILE');
$bundleRoot = getcwd();

if ($candidate !== G2_EXACT_CANDIDATE ||
    ! is_string($bundleRoot) || $bundleRoot === '' ||
    ! is_string($environmentFile) || trim($environmentFile) === '') {
    g2Stop('G2_IDENTITY_PRECONDITION_FAILED', 'bootstrap_preflight');
}

$environmentFile = realpath($environmentFile);
if ($environmentFile === false || ! is_file($environmentFile) || ! is_readable($environmentFile)) {
    g2Stop('G2_ENV_FILE_UNAVAILABLE', 'bootstrap_preflight');
}

try {
    require $bundleRoot.'/vendor/autoload.php';

    // Values are loaded into this process only. They are never copied, logged,
    // serialized or included in the sanitized evidence.
    Dotenv::createImmutable(dirname($environmentFile), basename($environmentFile))->load();

    if (g2RequiredEnvironment('DB_CONNECTION') !== 'mysql') {
        g2Stop('G2_DATABASE_DRIVER_UNSUPPORTED', 'database_configuration');
    }

    $database = g2RequiredEnvironment('DB_DATABASE');
    $socket = $_ENV['DB_SOCKET'] ?? $_SERVER['DB_SOCKET'] ?? getenv('DB_SOCKET');
    if (is_string($socket) && trim($socket) !== '') {
        $dsn = 'mysql:unix_socket='.$socket.';dbname='.$database.';charset=utf8mb4';
    } else {
        $host = g2RequiredEnvironment('DB_HOST');
        $port = $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? getenv('DB_PORT');
        $port = is_string($port) && preg_match('/^\d{1,5}$/', $port) ? $port : '3306';
        $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4';
    }

    $pdo = new PDO(
        $dsn,
        g2RequiredEnvironment('DB_USERNAME'),
        (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $g2Progress['database_connection'] = 'established';
    g2Checkpoint('database_connection');

    $sqlSafety = [
        'allowed_statement_classes' => ['SELECT'],
        'statement_counts' => ['SELECT' => 0],
        'total_statements' => 0,
        'rejected_statements' => 0,
        'statement_limit' => G2_SQL_LIMIT,
    ];
    $g2SqlSafety =& $sqlSafety;

    $server = g2Select($pdo, <<<'SQL'
SELECT
    VERSION() AS version,
    @@character_set_database AS character_set,
    @@collation_database AS collation
SQL, $sqlSafety)[0] ?? [];
    g2Checkpoint('database_identity');

    $affectedTables = g2Select($pdo, <<<'SQL'
SELECT
    TABLE_NAME AS table_name,
    COALESCE(TABLE_ROWS, 0) AS approximate_rows,
    COALESCE(DATA_LENGTH, 0) AS data_bytes,
    COALESCE(INDEX_LENGTH, 0) AS index_bytes
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'ai_proposals', 'ai_proposal_items', 'organizations', 'organization_users', 'users',
    'business_domains', 'projects', 'project_members', 'roadmaps', 'improvements', 'tasks'
  )
ORDER BY TABLE_NAME
SQL, $sqlSafety);
    g2Checkpoint('affected_table_metrics');

    $organizationUserCount = g2Select($pdo, <<<'SQL'
SELECT COUNT(*) AS row_count FROM organization_users
SQL, $sqlSafety)[0]['row_count'] ?? 0;
    g2Checkpoint('organization_users_row_count');

    $legacyRoleCounts = g2Select($pdo, <<<'SQL'
SELECT
    CASE
        WHEN role = 'owner' THEN 'owner'
        WHEN role = 'admin' THEN 'admin'
        WHEN role = 'member' THEN 'member'
        WHEN role = 'viewer' THEN 'viewer'
        ELSE 'other_or_null'
    END AS role_class,
    COUNT(*) AS row_count
FROM organization_users
GROUP BY role_class
ORDER BY role_class
SQL, $sqlSafety);
    g2Checkpoint('legacy_role_distribution');

    $expectedNewColumns = [
        'ai_proposals' => ['scope_type', 'scope_id', 'evidence'],
        'organization_users' => ['organization_role', 'position', 'membership_status', 'access_epoch', 'lifecycle_version', 'status_changed_at', 'status_changed_by_user_id', 'status_change_reason'],
        'organizations' => ['standard_workspace_id', 'personal_workspace_creation_enabled'],
        'users' => ['avatar_path', 'avatar_mime', 'avatar_width', 'avatar_height', 'avatar_updated_at'],
        'business_domains' => ['direction', 'direction_memo', 'display_order'],
        'projects' => ['project_type', 'execution_status', 'completion_check_version'],
        'project_members' => ['left_at', 'status_reason'],
        'roadmaps' => ['completion_check_version'],
        'improvements' => ['theme_description', 'execution_status', 'completion_check_version'],
        'tasks' => ['done_condition', 'review_status', 'review_requested_at', 'reviewed_at', 'reopened_at', 'last_change_reason'],
    ];
    $columnPredicates = [];
    foreach ($expectedNewColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnPredicates[] = "(TABLE_NAME = '".$table."' AND COLUMN_NAME = '".$column."')";
        }
    }
    $columnCollisions = g2Select($pdo, 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ('.implode(' OR ', $columnPredicates).') ORDER BY TABLE_NAME, COLUMN_NAME', $sqlSafety);
    g2Checkpoint('column_collision_preflight');

    $expectedNewTables = [
        'organization_groups', 'organization_group_memberships', 'organization_audit_events',
        'organization_invitations', 'organization_invitation_groups', 'organization_invitation_operations',
        'organization_membership_lifecycle_operations', 'owner_onboardings', 'owner_onboarding_operations',
        'owner_onboarding_audit_events', 'user_legal_consents', 'business_domain_items',
        'business_domain_item_attributes', 'business_domain_editor_grants', 'business_domain_operations',
        'business_domain_revisions', 'product_account_eligibilities', 'product_organization_compatibilities',
        'project_member_roles', 'project_group_audiences', 'project_execution_events', 'action_run_settings',
        'action_schedule_revisions', 'action_executions', 'action_execution_events',
    ];
    $quotedTables = implode(', ', array_map(static fn (string $table): string => "'".$table."'", $expectedNewTables));
    $tableCollisions = g2Select($pdo, 'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.$quotedTables.') ORDER BY TABLE_NAME', $sqlSafety);
    g2Checkpoint('table_collision_preflight');

    $transactionState = ['status' => 'SUPPORTED', 'active_count' => 0];
    try {
        $transactionState['active_count'] = (int) (g2Select($pdo, <<<'SQL'
SELECT COUNT(*) AS active_count
FROM information_schema.INNODB_TRX
WHERE trx_mysql_thread_id <> CONNECTION_ID()
SQL, $sqlSafety)[0]['active_count'] ?? 0);
    } catch (Throwable) {
        $transactionState = ['status' => 'UNSUPPORTED', 'active_count' => null];
    }
    g2Checkpoint('active_transaction_snapshot');

    $metadataLockState = ['status' => 'SUPPORTED', 'pending_count' => 0];
    try {
        $metadataLockState['pending_count'] = (int) (g2Select($pdo, <<<'SQL'
SELECT COUNT(*) AS pending_count
FROM performance_schema.metadata_locks
WHERE OBJECT_SCHEMA = DATABASE()
  AND LOCK_STATUS = 'PENDING'
SQL, $sqlSafety)[0]['pending_count'] ?? 0);
    } catch (Throwable) {
        $metadataLockState = ['status' => 'UNSUPPORTED', 'pending_count' => null];
    }
    g2Checkpoint('metadata_lock_snapshot');

    $roleCounts = [
        'owner' => 0,
        'admin' => 0,
        'member' => 0,
        'viewer' => 0,
        'other_or_null' => 0,
    ];
    foreach ($legacyRoleCounts as $row) {
        $class = (string) ($row['role_class'] ?? 'other_or_null');
        if (array_key_exists($class, $roleCounts)) {
            $roleCounts[$class] = (int) ($row['row_count'] ?? 0);
        }
    }

    $tableMetrics = [];
    foreach ($affectedTables as $row) {
        $tableMetrics[] = [
            'table_name' => (string) $row['table_name'],
            'approximate_rows' => (int) $row['approximate_rows'],
            'data_bytes' => (int) $row['data_bytes'],
            'index_bytes' => (int) $row['index_bytes'],
        ];
    }

    $evidence = [
        'output_schema_version' => G2_OUTPUT_SCHEMA_VERSION,
        'status' => 'PASS',
        'audit_mode' => 'read-only',
        'evidence_completeness' => 'complete_for_supported_g2_preflight_scope',
        'failure' => null,
        'evidence' => [
            'candidate' => G2_EXACT_CANDIDATE,
            'database' => [
                'engine_family' => 'MariaDB',
                'version' => (string) ($server['version'] ?? 'unknown'),
                'character_set' => (string) ($server['character_set'] ?? 'unknown'),
                'collation' => (string) ($server['collation'] ?? 'unknown'),
            ],
            'affected_table_metrics' => $tableMetrics,
            'organization_users' => [
                'exact_row_count' => (int) $organizationUserCount,
                'legacy_role_counts' => $roleCounts,
            ],
            'collision_preflight' => [
                'existing_expected_new_column_count' => count($columnCollisions),
                'existing_expected_new_table_count' => count($tableCollisions),
            ],
            'activity_snapshot' => [
                'active_transactions' => $transactionState,
                'pending_metadata_locks' => $metadataLockState,
                'snapshot_only' => true,
            ],
            'sql_safety' => $sqlSafety + [
                'result' => 'PASS',
                'persistent_db_write' => false,
                'ddl' => false,
                'migration_execution' => false,
            ],
            'capabilities' => [
                'affected_table_size_and_count' => 'SUPPORTED',
                'legacy_role_distribution' => 'SUPPORTED',
                'schema_collision_preflight' => 'SUPPORTED',
                'active_transaction_snapshot' => $transactionState['status'],
                'metadata_lock_snapshot' => $metadataLockState['status'],
                'external_writer_full_visibility' => 'UNSUPPORTED',
                'migration_runtime_prediction' => 'UNSUPPORTED',
            ],
        ],
        'secret_output' => false,
        'raw_identifier_output' => false,
        'raw_exception_output' => false,
        'production_change_scope' => 'none_read_only_g2_migration_preflight',
    ];

    fwrite(STDOUT, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
} catch (Throwable) {
    g2Stop('G2_READ_ONLY_PREFLIGHT_FAILED', 'database_read_only_preflight');
}
