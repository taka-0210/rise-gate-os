<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Release\MigrationReleaseGate;
use App\Services\Release\ProductionReadOnlyAudit;
use App\Services\Release\ReleaseManifestVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_allowlist_requires_exact_repository_ledger_and_approval(): void
    {
        $gate = app(MigrationReleaseGate::class);
        $repository = $gate->repositoryMigrations();
        $manifest = $this->manifest('migrations.json', [
            'schema_version' => 1,
            'repository_migrations_sha256' => hash('sha256', json_encode($repository, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'allowed_pending' => [],
            'migration_sha256' => [],
            'approval' => [
                'status' => 'approved',
                'approved_by' => 'release-reviewer',
                'approved_at_jst' => '2026-09-26 12:00:00 JST',
            ],
        ]);

        $this->assertSame('PASS', $gate->inspect($manifest, true)['result']);
        $this->assertSame('PASS', $gate->verifyApplied($manifest)['result']);

        $unapproved = $this->manifest('migrations-unapproved.json', [
            'schema_version' => 1,
            'repository_migrations_sha256' => hash('sha256', json_encode($repository, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'allowed_pending' => [],
            'migration_sha256' => [],
            'approval' => ['status' => 'pending'],
        ]);
        $this->expectException(\RuntimeException::class);
        $gate->inspect($unapproved, true);
    }

    public function test_release_verification_checks_tenant_owner_data_routes_and_assets(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Synthetic Staging', 'slug' => 'synthetic-staging']);
        OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => OrganizationUser::ROLE_OWNER,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Synthetic Workspace',
            'slug' => 'synthetic-workspace',
        ]);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Synthetic Project',
        ]);
        $manifest = $this->manifest('verification.json', [
            'schema_version' => 1,
            'release_case' => 'IR1-TEST',
            'rc_sha' => str_repeat('a', 40),
            'environment_label' => 'staging-synthetic',
            'required_routes' => ['login', 'company.home', 'projects.index'],
            'table_counts' => ['organizations' => ['minimum' => 1], 'projects' => ['minimum' => 1]],
            'required_records' => [['table' => 'projects', 'column' => 'id', 'ids' => [$project->id]]],
            'relations' => [[
                'table' => 'projects',
                'where' => ['id' => $project->id, 'organization_id' => $organization->id, 'owning_workspace_id' => $workspace->id],
                'minimum' => 1,
            ]],
            'owner_minimums' => [(string) $organization->id => 1],
            'asset_files' => [['path' => 'composer.lock', 'sha256' => hash_file('sha256', base_path('composer.lock'))]],
        ]);

        $result = app(ReleaseManifestVerifier::class)->verify($manifest, 'staging-synthetic');
        $this->assertSame('PASS', $result['result']);
        $this->assertSame(0, $result['failed_count']);
    }

    public function test_r0_audit_is_sanitized_and_reports_repository_delta(): void
    {
        $result = app(ProductionReadOnlyAudit::class)->collect();
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame('read-only', $result['audit_mode']);
        $this->assertSame('sqlite', $result['database']['driver']);
        $this->assertSame(95, $result['migrations']['repository_count']);
        $this->assertSame([], $result['migrations']['pending']);
        $this->assertArrayHasKey('organizations', $result['business_counts']);
        $this->assertStringNotContainsString('APP_KEY', $json);
        $this->assertStringNotContainsString('DB_PASSWORD', $json);
    }

    public function test_release_workflows_and_scripts_are_fail_closed(): void
    {
        $build = file_get_contents(base_path('.github/workflows/build-release-candidate.yml'));
        $deploy = file_get_contents(base_path('.github/workflows/deploy-production.yml'));
        $script = file_get_contents(base_path('deployment/deploy-release.sh'));
        $retired = file_get_contents(base_path('deployment/deploy-production.sh'));

        $this->assertStringContainsString('workflow_dispatch:', $build);
        $this->assertStringContainsString('ref: ${{ inputs.rc_sha }}', $build);
        $this->assertStringContainsString('npm ci', $build);
        $this->assertStringContainsString('npm run build', $build);
        $this->assertStringContainsString('rm -rf -- "$stage/storage"', file_get_contents(base_path('deployment/build-release-artifact.sh')));
        $this->assertStringContainsString('stage/database', file_get_contents(base_path('deployment/build-release-artifact.sh')));
        $this->assertStringContainsString('actions/download-artifact@v5', $deploy);
        $this->assertStringContainsString('run-id: ${{ inputs.release_run_id }}', $deploy);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $deploy);
        $this->assertStringNotContainsString('GITHUB_SHA}.tar.gz', $deploy);
        $this->assertStringContainsString('release:migrations:verify "$migration_manifest" --for-apply', $script);
        $this->assertStringContainsString('release:migrations:verify-applied', $script);
        $this->assertStringContainsString('approved manifest belongs to a different RC or release case', $script);
        $this->assertStringContainsString('current_link}.previous', $script);
        $this->assertStringNotContainsString('rsync --delete', $script);
        $this->assertStringNotContainsString('trap finish EXIT', $script);
        $this->assertStringContainsString('exit 78', $retired);
    }

    private function manifest(string $name, array $contents): string
    {
        $directory = storage_path('framework/testing/release-hardening');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, json_encode($contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
