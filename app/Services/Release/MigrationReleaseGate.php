<?php

namespace App\Services\Release;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MigrationReleaseGate
{
    public function inspect(string $manifestPath, bool $forApply = false): array
    {
        $manifest = $this->readManifest($manifestPath);
        $repository = $this->repositoryMigrations();
        $repositoryHash = hash('sha256', json_encode($repository, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (! hash_equals((string) ($manifest['repository_migrations_sha256'] ?? ''), $repositoryHash)) {
            throw new RuntimeException('Repository Migration set differs from the approved manifest.');
        }
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('Migration ledger does not exist.');
        }

        $applied = DB::table('migrations')->orderBy('migration')->pluck('migration')->map('strval')->all();
        $pending = array_values(array_diff(array_keys($repository), $applied));
        sort($pending);
        $allowed = array_values(array_map('strval', $manifest['allowed_pending'] ?? []));
        sort($allowed);

        if ($pending !== $allowed) {
            throw new RuntimeException('Actual pending Migrations differ from the approved allowlist.');
        }

        $approvedHashes = $manifest['migration_sha256'] ?? [];
        foreach ($allowed as $migration) {
            if (! isset($repository[$migration], $approvedHashes[$migration])
                || ! hash_equals($repository[$migration], (string) $approvedHashes[$migration])) {
                throw new RuntimeException("Migration checksum is not approved: {$migration}");
            }
        }

        if ($forApply) {
            $approval = $manifest['approval'] ?? [];
            if (($approval['status'] ?? null) !== 'approved'
                || trim((string) ($approval['approved_by'] ?? '')) === ''
                || trim((string) ($approval['approved_at_jst'] ?? '')) === '') {
                throw new RuntimeException('Migration manifest has no explicit approval.');
            }
        }

        return [
            'result' => 'PASS',
            'mode' => $forApply ? 'apply-preflight' : 'read-only-audit',
            'repository_migrations_sha256' => $repositoryHash,
            'repository_migration_count' => count($repository),
            'ledger_count' => count($applied),
            'allowed_pending' => $allowed,
            'approval_status' => (string) data_get($manifest, 'approval.status', 'pending'),
        ];
    }

    public function repositoryMigrations(): array
    {
        $migrations = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $migrations[$name] = hash_file('sha256', $path);
        }
        ksort($migrations);

        return $migrations;
    }

    public function verifyApplied(string $manifestPath): array
    {
        $manifest = $this->readManifest($manifestPath);
        $approval = $manifest['approval'] ?? [];
        if (($approval['status'] ?? null) !== 'approved'
            || trim((string) ($approval['approved_by'] ?? '')) === ''
            || trim((string) ($approval['approved_at_jst'] ?? '')) === '') {
            throw new RuntimeException('Migration manifest has no explicit approval.');
        }

        $repository = $this->repositoryMigrations();
        $repositoryHash = hash('sha256', json_encode($repository, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (! hash_equals((string) ($manifest['repository_migrations_sha256'] ?? ''), $repositoryHash)) {
            throw new RuntimeException('Repository Migration set differs from the approved manifest.');
        }
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('Migration ledger does not exist.');
        }

        $applied = DB::table('migrations')->pluck('migration')->map('strval')->all();
        $pending = array_values(array_diff(array_keys($repository), $applied));
        $allowed = array_values(array_map('strval', $manifest['allowed_pending'] ?? []));
        $missingApproved = array_values(array_diff($allowed, $applied));
        if ($pending !== [] || $missingApproved !== []) {
            throw new RuntimeException('Migration postflight did not reach the exact approved ledger state.');
        }

        return [
            'result' => 'PASS',
            'mode' => 'apply-postflight',
            'repository_migrations_sha256' => $repositoryHash,
            'ledger_count' => count($applied),
            'approved_migrations_applied' => $allowed,
            'pending' => [],
        ];
    }

    private function readManifest(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw new RuntimeException('Migration manifest does not exist.');
        }
        $manifest = json_decode((string) file_get_contents($real), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported Migration manifest.');
        }

        return $manifest;
    }
}
