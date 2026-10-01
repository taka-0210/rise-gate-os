<?php

namespace App\Services\Release;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductionReadOnlyAudit
{
    private const BUSINESS_TABLES = [
        'organizations', 'workspaces', 'projects', 'project_members', 'roadmaps', 'improvements',
        'tasks', 'action_executions', 'company_financial_periods', 'company_loans', 'business_domains',
        'business_domain_items', 'users', 'organization_users', 'workspace_members',
        'product_account_eligibilities', 'organization_invitations',
    ];

    /** @param array<string, mixed> $bundleIdentity */
    public function collect(array $bundleIdentity = []): array
    {
        $connection = DB::connection();
        $sqlGuard = new ReadOnlySqlGuard;
        $sqlGuard->install($connection);
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();
        $migrationFiles = app(MigrationReleaseGate::class)->repositoryMigrations();
        $ledger = Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('migration')->get(['migration', 'batch'])->map(fn ($row) => [
                'migration' => (string) $row->migration,
                'batch' => (int) $row->batch,
            ])->all()
            : [];
        $applied = array_column($ledger, 'migration');

        $counts = [];
        foreach (self::BUSINESS_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return [
            'audit_mode' => 'read-only',
            'generated_at_jst' => now()->timezone('Asia/Tokyo')->format('Y-m-d H:i:s T'),
            'bundle' => $bundleIdentity,
            'application' => [
                'environment' => app()->environment(),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'base_path_ref' => $this->maskIdentifier('application_path', base_path()),
            ],
            'database' => [
                'driver' => $driver,
                'server_version' => (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
                'identifier_ref' => $this->maskIdentifier('database', $database),
                'charset' => (string) config("database.connections.{$connection->getName()}.charset", ''),
                'collation' => (string) config("database.connections.{$connection->getName()}.collation", ''),
            ],
            'runtime' => [
                'cache_store' => (string) config('cache.default'),
                'session_driver' => (string) config('session.driver'),
                'queue_connection' => (string) config('queue.default'),
                'filesystem_default' => (string) config('filesystems.default'),
                'cron_actual_state' => 'not_observable_from_application',
                'worker_actual_state' => 'not_observable_from_application',
                'external_writers' => 'not_observable_from_application',
            ],
            'migrations' => [
                'repository_count' => count($migrationFiles),
                'repository_sha256' => hash('sha256', json_encode($migrationFiles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'ledger' => $ledger,
                'pending' => array_values(array_diff(array_keys($migrationFiles), $applied)),
                'ledger_only' => array_values(array_diff($applied, array_keys($migrationFiles))),
            ],
            'business_counts' => $counts,
            'active_owners' => $this->activeOwnerCounts(),
            'pending_invitation_count' => Schema::hasTable('organization_invitations')
                ? DB::table('organization_invitations')->where('status', 'pending')->count()
                : null,
            'schema' => $this->schemaInventory($driver, $database),
            'sql_safety' => $sqlGuard->evidence(),
            'capabilities' => [
                'application_runtime_config' => 'SUPPORTED',
                'database_metadata' => 'SUPPORTED',
                'migration_ledger_and_pending' => 'SUPPORTED',
                'masked_owner_presence' => 'SUPPORTED',
                'filesystem_topology' => 'UNSUPPORTED',
                'process_state' => 'UNSUPPORTED',
                'backup_restore_readiness' => 'UNSUPPORTED',
            ],
        ];
    }

    private function activeOwnerCounts(): array
    {
        if (! Schema::hasTable('organization_users')) {
            return [];
        }

        $query = DB::table('organization_users')
            ->select('organization_id', DB::raw('COUNT(*) AS owner_count'));

        if (Schema::hasColumn('organization_users', 'membership_status')) {
            $query->where('membership_status', 'active');
        }
        if (Schema::hasColumn('organization_users', 'organization_role')) {
            $query->where('organization_role', 'owner');
        } elseif (Schema::hasColumn('organization_users', 'role')) {
            $query->where('role', 'owner');
        } else {
            return [];
        }

        return $query
            ->groupBy('organization_id')
            ->orderBy('organization_id')
            ->get()
            ->map(fn ($row) => [
                'organization_ref' => $this->maskIdentifier('organization', (string) $row->organization_id),
                'owner_count' => (int) $row->owner_count,
            ])
            ->all();
    }

    private function schemaInventory(string $driver, string $database): array
    {
        if ($driver === 'sqlite') {
            return DB::select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE type IN ('table','index') AND name NOT LIKE 'sqlite_%' ORDER BY type, name");
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return [
                'tables' => DB::select('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database]),
                'columns' => DB::select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$database]),
                'indexes' => DB::select('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [$database]),
                'foreign_keys' => DB::select('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION', [$database]),
                'check_constraints' => DB::select(<<<'SQL'
                    SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
                    FROM information_schema.TABLE_CONSTRAINTS tc
                    INNER JOIN information_schema.CHECK_CONSTRAINTS cc
                        ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                        AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                    WHERE tc.CONSTRAINT_SCHEMA = ? AND tc.CONSTRAINT_TYPE = 'CHECK'
                    ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME
                    SQL, [$database]),
            ];
        }

        return ['unsupported_driver' => $driver];
    }

    private function maskIdentifier(string $type, string $identifier): string
    {
        $key = (string) config('app.key');
        if (strlen($key) < 16) {
            throw new R0AuditSafetyException('R0_MASKING_KEY_UNAVAILABLE', 'identifier_masking');
        }

        return $type.'_hmac_sha256:'.hash_hmac('sha256', $type.':'.$identifier, $key);
    }
}
