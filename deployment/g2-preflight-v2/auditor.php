<?php

declare(strict_types=1);

use Dotenv\Dotenv;

const G2_V2_SCHEMA = 2;
const G2_V2_CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';
const G2_V2_SQL_LIMIT = 24;

$progress = [
    'database_connection' => 'not_attempted',
    'last_completed_check' => 'none',
    'completed_checks' => [],
];
$sql = [
    'allowed_statement_classes' => ['SELECT'],
    'statement_counts' => ['SELECT' => 0],
    'total_statements' => 0,
    'rejected_statements' => 0,
    'statement_limit' => G2_V2_SQL_LIMIT,
    'persistent_db_write' => false,
    'ddl' => false,
    'migration_execution' => false,
];

/** @param array<string, mixed> $data */
function frame(string $layer, string $event, string $status, array $data = []): void
{
    static $sequence = 0;
    $sequence++;
    fwrite(STDOUT, json_encode([
        'schema_version' => G2_V2_SCHEMA,
        'sequence' => $sequence,
        'layer' => $layer,
        'event' => $event,
        'status' => $status,
        'data' => $data,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    fflush(STDOUT);
}

function checkpoint(string $name): void
{
    global $progress, $sql;
    $progress['last_completed_check'] = $name;
    $progress['completed_checks'][] = $name;
    frame('database', 'check_completed', 'PASS', [
        'check' => $name,
        'database_connection' => $progress['database_connection'],
        'sql_total_statements' => $sql['total_statements'],
        'sql_rejected_statements' => $sql['rejected_statements'],
    ]);
}

/** @return never */
function stop(string $code, string $stage): void
{
    global $progress, $sql;
    frame('php', 'terminal', 'STOP', [
        'safe_error_code' => $code,
        'failure_stage' => $stage,
        'evidence_completeness' => 'partial',
        'database_connection' => $progress['database_connection'],
        'last_completed_check' => $progress['last_completed_check'],
        'completed_checks' => $progress['completed_checks'],
        'sql_safety' => $sql,
        'production_change_scope' => 'none_read_only_g2_preflight_v2',
        'secret_output' => false,
        'raw_identifier_output' => false,
        'raw_exception_output' => false,
    ]);
    exit(1);
}

/** @return list<array<string, mixed>> */
function selectOnly(PDO $pdo, string $statement): array
{
    global $sql;
    if (! preg_match('/^\s*SELECT\b/i', $statement)) {
        $sql['rejected_statements']++;
        stop('G2_V2_NON_SELECT_REJECTED', 'sql_guard');
    }
    $sql['total_statements']++;
    $sql['statement_counts']['SELECT']++;
    if ($sql['total_statements'] > G2_V2_SQL_LIMIT) {
        stop('G2_V2_SQL_LIMIT_EXCEEDED', 'sql_guard');
    }
    $result = $pdo->query($statement);
    if ($result === false) {
        throw new RuntimeException('query_failed');
    }
    return $result->fetchAll(PDO::FETCH_ASSOC);
}

function requiredEnvironment(string $name): string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if (! is_string($value) || trim($value) === '') {
        stop('G2_V2_DATABASE_CONFIG_UNAVAILABLE', 'database_configuration');
    }
    return $value;
}

frame('php', 'contract_started', 'PASS', [
    'candidate' => G2_V2_CANDIDATE,
    'application_bootstrap' => 'not_used',
    'database_connection' => 'not_attempted',
]);

$candidate = getenv('G2_V2_CANDIDATE');
$bundleRoot = getenv('G2_V2_BUNDLE_ROOT');
$environmentFile = getenv('IR1_R0_ENV_FILE');
if ($candidate !== G2_V2_CANDIDATE || ! is_string($bundleRoot) || ! is_string($environmentFile)) {
    stop('G2_V2_IDENTITY_PRECONDITION_FAILED', 'identity_preflight');
}
$bundleRoot = realpath($bundleRoot);
$environmentFile = realpath($environmentFile);
if ($bundleRoot === false || $environmentFile === false || ! is_file($environmentFile)
    || ! is_readable($environmentFile) || ! is_file($bundleRoot.'/vendor/autoload.php')) {
    stop('G2_V2_RUNTIME_INPUT_UNAVAILABLE', 'identity_preflight');
}

try {
    require $bundleRoot.'/vendor/autoload.php';
    Dotenv::createImmutable(dirname($environmentFile), basename($environmentFile))->load();
    checkpoint('environment_loaded');

    if (requiredEnvironment('DB_CONNECTION') !== 'mysql') {
        stop('G2_V2_DATABASE_DRIVER_UNSUPPORTED', 'database_configuration');
    }
    $database = requiredEnvironment('DB_DATABASE');
    $socket = $_ENV['DB_SOCKET'] ?? $_SERVER['DB_SOCKET'] ?? getenv('DB_SOCKET');
    if (is_string($socket) && trim($socket) !== '') {
        $dsn = 'mysql:unix_socket='.$socket.';dbname='.$database.';charset=utf8mb4';
    } else {
        $host = requiredEnvironment('DB_HOST');
        $port = $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? getenv('DB_PORT');
        $port = is_string($port) && preg_match('/^\d{1,5}$/', $port) ? $port : '3306';
        $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4';
    }
    $pdo = new PDO($dsn, requiredEnvironment('DB_USERNAME'), (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $progress['database_connection'] = 'established';
    checkpoint('database_connection');

    $server = selectOnly($pdo, 'SELECT VERSION() AS version, @@character_set_database AS character_set, @@collation_database AS collation')[0] ?? [];
    checkpoint('database_identity');

    $affected = selectOnly($pdo, "SELECT TABLE_NAME AS table_name, COALESCE(TABLE_ROWS, 0) AS approximate_rows, COALESCE(DATA_LENGTH, 0) AS data_bytes, COALESCE(INDEX_LENGTH, 0) AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ai_proposals','ai_proposal_items','organizations','organization_users','users','business_domains','projects','project_members','roadmaps','improvements','tasks') ORDER BY TABLE_NAME");
    checkpoint('affected_table_metrics');
    $organizationUserCount = selectOnly($pdo, 'SELECT COUNT(*) AS row_count FROM organization_users')[0]['row_count'] ?? 0;
    checkpoint('organization_users_row_count');
    $legacyRoles = selectOnly($pdo, "SELECT CASE WHEN role='owner' THEN 'owner' WHEN role='admin' THEN 'admin' WHEN role='member' THEN 'member' WHEN role='viewer' THEN 'viewer' ELSE 'other_or_null' END AS role_class, COUNT(*) AS row_count FROM organization_users GROUP BY role_class ORDER BY role_class");
    checkpoint('legacy_role_distribution');

    $columnCollisions = selectOnly($pdo, "SELECT COUNT(*) AS collision_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='ai_proposals' AND COLUMN_NAME IN ('scope_type','scope_id','evidence')) OR (TABLE_NAME='organization_users' AND COLUMN_NAME IN ('organization_role','position','membership_status','access_epoch','lifecycle_version','status_changed_at','status_changed_by_user_id','status_change_reason')) OR (TABLE_NAME='organizations' AND COLUMN_NAME IN ('standard_workspace_id','personal_workspace_creation_enabled')) OR (TABLE_NAME='users' AND COLUMN_NAME IN ('avatar_path','avatar_mime','avatar_width','avatar_height','avatar_updated_at')) OR (TABLE_NAME='business_domains' AND COLUMN_NAME IN ('direction','direction_memo','display_order')) OR (TABLE_NAME='projects' AND COLUMN_NAME IN ('project_type','execution_status','completion_check_version')) OR (TABLE_NAME='project_members' AND COLUMN_NAME IN ('left_at','status_reason')) OR (TABLE_NAME='roadmaps' AND COLUMN_NAME='completion_check_version') OR (TABLE_NAME='improvements' AND COLUMN_NAME IN ('theme_description','execution_status','completion_check_version')) OR (TABLE_NAME='tasks' AND COLUMN_NAME IN ('done_condition','review_status','review_requested_at','reviewed_at','reopened_at','last_change_reason')))")[0]['collision_count'] ?? 0;
    checkpoint('column_collision_preflight');
    $tableCollisions = selectOnly($pdo, "SELECT COUNT(*) AS collision_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('organization_groups','organization_group_memberships','organization_audit_events','organization_invitations','organization_invitation_groups','organization_invitation_operations','organization_membership_lifecycle_operations','owner_onboardings','owner_onboarding_operations','owner_onboarding_audit_events','user_legal_consents','business_domain_items','business_domain_item_attributes','business_domain_editor_grants','business_domain_operations','business_domain_revisions','product_account_eligibilities','product_organization_compatibilities','project_member_roles','project_group_audiences','project_execution_events','action_run_settings','action_schedule_revisions','action_executions','action_execution_events')")[0]['collision_count'] ?? 0;
    checkpoint('table_collision_preflight');

    $transactions = ['status' => 'SUPPORTED', 'active_count' => 0];
    try {
        $transactions['active_count'] = (int) (selectOnly($pdo, 'SELECT COUNT(*) AS active_count FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id <> CONNECTION_ID()')[0]['active_count'] ?? 0);
    } catch (Throwable) {
        $transactions = ['status' => 'UNSUPPORTED', 'active_count' => null];
    }
    checkpoint('active_transaction_snapshot');
    $locks = ['status' => 'SUPPORTED', 'pending_count' => 0];
    try {
        $locks['pending_count'] = (int) (selectOnly($pdo, "SELECT COUNT(*) AS pending_count FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA=DATABASE() AND LOCK_STATUS='PENDING'")[0]['pending_count'] ?? 0);
    } catch (Throwable) {
        $locks = ['status' => 'UNSUPPORTED', 'pending_count' => null];
    }
    checkpoint('metadata_lock_snapshot');

    $roleCounts = ['owner' => 0, 'admin' => 0, 'member' => 0, 'viewer' => 0, 'other_or_null' => 0];
    foreach ($legacyRoles as $row) {
        $class = (string) ($row['role_class'] ?? 'other_or_null');
        if (array_key_exists($class, $roleCounts)) {
            $roleCounts[$class] = (int) ($row['row_count'] ?? 0);
        }
    }
    $tableMetrics = array_map(static fn (array $row): array => [
        'table_name' => (string) $row['table_name'],
        'approximate_rows' => (int) $row['approximate_rows'],
        'data_bytes' => (int) $row['data_bytes'],
        'index_bytes' => (int) $row['index_bytes'],
    ], $affected);

    frame('php', 'terminal', 'PASS', [
        'candidate' => G2_V2_CANDIDATE,
        'evidence_completeness' => 'complete_for_supported_scope',
        'database' => [
            'engine_family' => str_contains((string) ($server['version'] ?? ''), 'MariaDB') ? 'MariaDB' : 'unknown',
            'version' => (string) ($server['version'] ?? 'unknown'),
            'character_set' => (string) ($server['character_set'] ?? 'unknown'),
            'collation' => (string) ($server['collation'] ?? 'unknown'),
        ],
        'affected_table_metrics' => $tableMetrics,
        'organization_users' => ['exact_row_count' => (int) $organizationUserCount, 'legacy_role_counts' => $roleCounts],
        'collision_preflight' => ['existing_expected_new_column_count' => (int) $columnCollisions, 'existing_expected_new_table_count' => (int) $tableCollisions],
        'activity_snapshot' => ['active_transactions' => $transactions, 'pending_metadata_locks' => $locks, 'snapshot_only' => true],
        'sql_safety' => $sql,
        'capabilities' => [
            'migration_runtime_prediction' => 'UNSUPPORTED',
            'external_writer_full_visibility' => 'UNSUPPORTED',
        ],
        'production_change_scope' => 'none_read_only_g2_preflight_v2',
        'secret_output' => false,
        'raw_identifier_output' => false,
        'raw_exception_output' => false,
    ]);
    exit(0);
} catch (Throwable) {
    stop('G2_V2_READ_ONLY_PREFLIGHT_FAILED', 'database_read_only_preflight');
}
