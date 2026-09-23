<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountSeparation\AccountSeparationCutover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class RunAccountSeparationCutover extends Command
{
    protected $signature = 'company-os:account-separation
        {--phase=dry-run : dry-run, phase-a, phase-b, phase-c, compensate, or phase-e}
        {--apply : Allow the selected mutation phase after every guard passes}
        {--case-id= : Approved AS-G04 case identifier}
        {--expected-head= : Exact approved repository HEAD}
        {--expected-database-sha256= : Exact normal-local DB SHA-256}
        {--expected-inventory-hash= : Exact dry-run inventory hash}
        {--new-user-id= : Actual new account User ID for phase-c and later}
        {--confirm-environment= : Must be AS-G04-NORMAL-LOCAL}
        {--confirm-finalize= : Separate Phase E acceptance phrase}';

    protected $description = 'Guarded one-time runner for the approved Company OS account separation.';

    public function handle(AccountSeparationCutover $cutover): int
    {
        $phase = strtolower(trim((string) $this->option('phase')));
        if (! in_array($phase, ['dry-run', 'phase-a', 'phase-b', 'phase-c', 'compensate', 'phase-e'], true)) {
            $this->error('Unknown phase.');

            return self::INVALID;
        }

        try {
            $environment = $this->environmentEvidence();
            $inventory = $cutover->inventory($phase);
            $report = [
                'mode' => $this->option('apply') ? 'apply-requested' : 'dry-run',
                'phase' => $phase,
                'environment' => $environment,
                'schema' => $this->schemaEvidence(),
                'inventory' => $inventory,
                'inventory_hash' => $cutover->inventoryHash($inventory),
                'backup_plan' => [
                    'directory' => storage_path('app/private/backups/account-separation'),
                    'method' => 'SQLite VACUUM INTO before the first DB mutation',
                    'retention_days' => 30,
                    'delete_after_jst' => now()->addDays(30)->format('Y-m-d H:i:s T'),
                ],
                'guards' => $this->guardSummary($phase),
            ];

            if (! $this->option('apply')) {
                $report['result'] = 'DRY_RUN_PASS';
                $this->line($this->json($report));

                return self::SUCCESS;
            }

            $this->assertApplyGuards($phase, $environment, $report['schema'], $report['inventory_hash']);
            $caseId = trim((string) $this->option('case-id'));
            $actor = User::query()->findOrFail(AccountSeparationCutover::OLD_USER_ID);
            $result = match ($phase) {
                'phase-a' => $this->phaseA($cutover, $actor, $caseId, $environment),
                'phase-b' => $this->phaseB($cutover, $actor, $caseId),
                'phase-c' => $cutover->cutover($caseId, $this->newUserId(), (string) $this->option('expected-inventory-hash')),
                'compensate' => $cutover->compensate($caseId, $this->newUserId(), (string) $this->option('expected-inventory-hash')),
                'phase-e' => $cutover->finalize($caseId, $this->newUserId()),
                default => throw new RuntimeException('dry-run cannot be applied.'),
            };
            $report['result'] = 'APPLIED';
            $report['phase_result'] = $result;
            $this->line($this->json($report));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Account separation runner stopped: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function phaseA(
        AccountSeparationCutover $cutover,
        User $actor,
        string $caseId,
        array $environment,
    ): array {
        $backup = $this->createBackup($caseId, $environment);
        try {
            $workspace = $cutover->prepareStandardWorkspace($actor, $caseId);
        } catch (\Throwable $exception) {
            $backup['database_mutation'] = 'not_committed';
            throw $exception;
        }

        return ['backup' => $backup, 'standard_workspace' => $workspace];
    }

    private function phaseB(AccountSeparationCutover $cutover, User $actor, string $caseId): array
    {
        $invitation = $cutover->issueInvitation($actor, $caseId);

        return [
            'invitation_id' => $invitation->id,
            'invitation_public_id' => $invitation->public_id,
            'status' => $invitation->status,
            'delivery_status' => $invitation->delivery_status,
        ];
    }

    private function environmentEvidence(): array
    {
        $database = (string) DB::connection()->getDatabaseName();
        $realDatabase = realpath($database);
        $normalLocal = realpath(database_path('database.sqlite'));
        if ($realDatabase === false || $normalLocal === false) {
            throw new RuntimeException('SQLite database path cannot be resolved.');
        }
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->mustRun();
        $status = new Process(['git', 'status', '--porcelain'], base_path());
        $status->mustRun();

        return [
            'app_env' => app()->environment(),
            'database_connection' => DB::getDefaultConnection(),
            'database_path' => $realDatabase,
            'is_normal_local_database' => $realDatabase === $normalLocal,
            'database_size' => filesize($realDatabase),
            'database_sha256' => hash_file('sha256', $realDatabase),
            'database_integrity' => DB::scalar('PRAGMA integrity_check'),
            'repository_head' => trim($process->getOutput()),
            'working_tree_clean' => trim($status->getOutput()) === '',
            'admission_enabled' => (bool) config('product_ux.organization_admission_enabled'),
            'cutover_apply_enabled' => (bool) config('account_separation.cutover_enabled'),
            'finalize_enabled' => (bool) config('account_separation.finalize_enabled'),
        ];
    }

    private function schemaEvidence(): array
    {
        $requiredTables = [
            'users', 'organizations', 'organization_users', 'workspaces', 'workspace_members',
            'organization_invitations', 'organization_membership_lifecycle_operations',
            'product_account_eligibilities', 'product_organization_compatibilities',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
        $migrationFiles = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            glob(database_path('migrations/*.php')) ?: [],
        );
        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = array_values(array_diff($migrationFiles, $ran));

        return [
            'required_tables_present' => $missingTables === [],
            'missing_tables' => $missingTables,
            'migration_files' => count($migrationFiles),
            'pending_migrations' => $pending,
        ];
    }

    private function guardSummary(string $phase): array
    {
        return [
            'default_is_dry_run' => true,
            'apply_requires_enable_flag' => true,
            'apply_requires_normal_local_confirmation' => true,
            'apply_requires_exact_head_database_and_inventory_hashes' => true,
            'phase_b_and_c_require_admission_enabled' => in_array($phase, ['phase-b', 'phase-c'], true),
            'phase_e_requires_separate_flag_and_acceptance_phrase' => true,
            'production_allowed' => false,
        ];
    }

    private function assertApplyGuards(string $phase, array $environment, array $schema, string $inventoryHash): void
    {
        if ($phase === 'dry-run') {
            throw new RuntimeException('dry-run is read-only and cannot be applied.');
        }
        if (! $environment['is_normal_local_database']
            || $environment['app_env'] !== 'local'
            || $environment['database_connection'] !== 'sqlite') {
            throw new RuntimeException('Apply is restricted to the approved normal-local SQLite database.');
        }
        if ($environment['database_integrity'] !== 'ok'
            || ! $schema['required_tables_present']
            || $schema['pending_migrations'] !== []) {
            throw new RuntimeException('Database integrity, schema, or Migration gate is not satisfied.');
        }
        if (! $environment['cutover_apply_enabled']) {
            throw new RuntimeException('ACCOUNT_SEPARATION_CUTOVER_ENABLED is not enabled.');
        }
        if ((string) $this->option('confirm-environment') !== 'AS-G04-NORMAL-LOCAL') {
            throw new RuntimeException('Normal-local confirmation phrase is missing.');
        }
        if (! $environment['working_tree_clean']) {
            throw new RuntimeException('Working tree must be clean before apply.');
        }
        if (trim((string) $this->option('case-id')) === '') {
            throw new RuntimeException('Approved case ID is required.');
        }
        if (! hash_equals($environment['repository_head'], trim((string) $this->option('expected-head')))) {
            throw new RuntimeException('Repository HEAD differs from the approved value.');
        }
        if (! hash_equals($environment['database_sha256'], strtolower(trim((string) $this->option('expected-database-sha256'))))) {
            throw new RuntimeException('Database SHA-256 differs from the approved value.');
        }
        if (! hash_equals($inventoryHash, strtolower(trim((string) $this->option('expected-inventory-hash'))))) {
            throw new RuntimeException('Inventory hash differs from the approved value.');
        }
        if (in_array($phase, ['phase-b', 'phase-c'], true) && ! $environment['admission_enabled']) {
            throw new RuntimeException('Admission must be enabled for this phase.');
        }
        if ($phase === 'phase-e'
            && (! $environment['finalize_enabled']
                || (string) $this->option('confirm-finalize') !== 'PHASE-D-ACCEPTED-BY-TAKAMI-MASAYA')) {
            throw new RuntimeException('Phase E has not received its separate acceptance guard.');
        }
    }

    private function createBackup(string $caseId, array $environment): array
    {
        $directory = storage_path('app/private/backups/account-separation');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Backup directory could not be created.');
        }
        $safeCase = preg_replace('/[^A-Za-z0-9_.-]/', '-', $caseId) ?: 'as-g04';
        $createdAt = now()->timezone('Asia/Tokyo');
        $path = $directory.DIRECTORY_SEPARATOR.$safeCase.'-pre-cutover-'.$createdAt->format('Ymd-His').'.sqlite';
        if (file_exists($path)) {
            throw new RuntimeException('Backup target already exists.');
        }
        $source = $environment['database_path'];
        $pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Source database integrity check failed.');
        }
        $pdo->exec('VACUUM INTO '.$pdo->quote(str_replace('\\', '/', $path)));
        unset($pdo);
        $backupPdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($backupPdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Backup integrity check failed.');
        }
        unset($backupPdo);
        if (! hash_equals($environment['database_sha256'], hash_file('sha256', $source))) {
            throw new RuntimeException('Normal-local database changed while the backup was created.');
        }

        return [
            'path' => $path,
            'size' => filesize($path),
            'sha256' => hash_file('sha256', $path),
            'created_at_jst' => $createdAt->format('Y-m-d H:i:s T'),
            'retain_until_jst' => $createdAt->copy()->addDays(30)->format('Y-m-d H:i:s T'),
            'integrity' => 'ok',
        ];
    }

    private function newUserId(): int
    {
        $id = filter_var($this->option('new-user-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Actual new User ID is required.');
        }

        return (int) $id;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
