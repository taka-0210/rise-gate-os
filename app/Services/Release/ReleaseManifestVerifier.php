<?php

namespace App\Services\Release;

use App\Models\OrganizationUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ReleaseManifestVerifier
{
    public function verify(string $manifestPath, string $environment): array
    {
        $manifest = $this->readManifest($manifestPath);
        if (! hash_equals((string) ($manifest['environment_label'] ?? ''), $environment)) {
            throw new RuntimeException('Verification environment differs from the manifest.');
        }

        $checks = [];
        foreach ($manifest['required_routes'] ?? [] as $name) {
            $this->record($checks, 'route:'.$name, Route::has((string) $name));
        }
        foreach ($manifest['table_counts'] ?? [] as $table => $expectation) {
            $this->assertIdentifier((string) $table);
            $exists = Schema::hasTable((string) $table);
            $count = $exists ? DB::table((string) $table)->count() : null;
            $passes = $exists
                && (! isset($expectation['exact']) || $count === (int) $expectation['exact'])
                && (! isset($expectation['minimum']) || $count >= (int) $expectation['minimum']);
            $this->record($checks, 'count:'.$table, $passes, ['count' => $count]);
        }
        foreach ($manifest['required_records'] ?? [] as $record) {
            $table = (string) ($record['table'] ?? '');
            $column = (string) ($record['column'] ?? 'id');
            $this->assertIdentifier($table);
            $this->assertIdentifier($column);
            $ids = array_values($record['ids'] ?? []);
            $actual = Schema::hasTable($table) ? DB::table($table)->whereIn($column, $ids)->count() : 0;
            $this->record($checks, "records:{$table}.{$column}", $actual === count($ids), ['expected' => count($ids), 'actual' => $actual]);
        }
        foreach ($manifest['relations'] ?? [] as $index => $relation) {
            $table = (string) ($relation['table'] ?? '');
            $this->assertIdentifier($table);
            $query = DB::table($table);
            foreach ($relation['where'] ?? [] as $column => $value) {
                $this->assertIdentifier((string) $column);
                $query->where((string) $column, $value);
            }
            $actual = $query->count();
            $minimum = (int) ($relation['minimum'] ?? 1);
            $this->record($checks, 'relation:'.$index, $actual >= $minimum, ['minimum' => $minimum, 'actual' => $actual]);
        }
        foreach ($manifest['owner_minimums'] ?? [] as $organizationId => $minimum) {
            $actual = DB::table('organization_users')
                ->where('organization_id', $organizationId)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
                ->count();
            $this->record($checks, 'active-owner:'.$organizationId, $actual >= (int) $minimum, ['minimum' => (int) $minimum, 'actual' => $actual]);
        }
        foreach ($manifest['asset_files'] ?? [] as $asset) {
            $relative = str_replace('\\', '/', (string) ($asset['path'] ?? ''));
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
                throw new RuntimeException('Asset path must remain inside the release.');
            }
            $path = base_path($relative);
            $exists = is_file($path);
            $passes = $exists && (! isset($asset['sha256']) || hash_equals((string) $asset['sha256'], hash_file('sha256', $path)));
            $this->record($checks, 'asset:'.$relative, $passes, ['exists' => $exists]);
        }

        $failed = array_values(array_filter($checks, fn (array $check): bool => ! $check['pass']));

        return [
            'result' => $failed === [] ? 'PASS' : 'FAIL',
            'release_case' => (string) ($manifest['release_case'] ?? ''),
            'rc_sha' => (string) ($manifest['rc_sha'] ?? ''),
            'environment_label' => $environment,
            'checks' => $checks,
            'failed_count' => count($failed),
        ];
    }

    private function readManifest(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw new RuntimeException('Release verification manifest does not exist.');
        }
        $manifest = json_decode((string) file_get_contents($real), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported release verification manifest.');
        }

        return $manifest;
    }

    private function assertIdentifier(string $value): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new RuntimeException('Unsafe schema identifier in verification manifest.');
        }
    }

    private function record(array &$checks, string $name, bool $pass, array $evidence = []): void
    {
        $checks[] = ['name' => $name, 'pass' => $pass, 'evidence' => $evidence];
    }
}
