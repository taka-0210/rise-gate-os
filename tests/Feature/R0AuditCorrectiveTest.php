<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Release\ProductionReadOnlyAudit;
use App\Services\Release\R0AuditBundleVerifier;
use App\Services\Release\R0AuditSafetyException;
use App\Services\Release\R0BundleHash;
use App\Services\Release\ReadOnlySqlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class R0AuditCorrectiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_audit_masks_identifiers_and_executes_read_only_sql(): void
    {
        config()->set('app.key', 'secret-output-canary-key-0001');
        config()->set('services.openai.key', 'provider-secret-canary');
        Http::fake();
        Mail::fake();
        Queue::fake();

        $owner = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'R0 Synthetic', 'slug' => 'r0-synthetic']);
        OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'organization_role' => 'owner',
            'membership_status' => 'active',
            'joined_at' => now(),
        ]);

        $result = app(ProductionReadOnlyAudit::class)->collect([
            'source_commit' => R0AuditBundleVerifier::EXACT_CANDIDATE_COMMIT,
            'integrity' => 'PASS',
        ]);
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertArrayNotHasKey('organization_id', $result['active_owners'][0]);
        $this->assertStringStartsWith('organization_hmac_sha256:', $result['active_owners'][0]['organization_ref']);
        $this->assertStringStartsWith('database_hmac_sha256:', $result['database']['identifier_ref']);
        $this->assertStringStartsWith('application_path_hmac_sha256:', $result['application']['base_path_ref']);
        $this->assertArrayNotHasKey('identifier_sha256', $result['database']);
        $this->assertArrayNotHasKey('base_path_sha256', $result['application']);
        $this->assertStringNotContainsString('secret-output-canary-key-0001', $json);
        $this->assertStringNotContainsString('provider-secret-canary', $json);
        $this->assertSame('PASS', $result['sql_safety']['result']);
        $this->assertSame(0, $result['sql_safety']['rejected_statements']);
        $this->assertGreaterThan(0, $result['sql_safety']['total_statements']);
        $this->assertSame([], array_diff(array_keys($result['sql_safety']['statement_counts']), ['SELECT', 'PRAGMA', 'SHOW', 'DESCRIBE']));
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_sql_guard_rejects_mutation_before_execution(): void
    {
        $user = User::factory()->create(['name' => 'Before']);
        $guard = new ReadOnlySqlGuard;
        $guard->install(DB::connection());

        try {
            DB::table('users')->where('id', $user->id)->update(['name' => 'After']);
            $this->fail('A write statement was not rejected.');
        } catch (R0AuditSafetyException $exception) {
            $this->assertSame('R0_SQL_NOT_READ_ONLY', $exception->safeErrorCode());
        }

        $this->assertSame('Before', DB::table('users')->where('id', $user->id)->value('name'));
    }

    public function test_failure_output_never_contains_raw_exception(): void
    {
        $this->mock(R0AuditBundleVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturn([
                'source_commit' => R0AuditBundleVerifier::EXACT_CANDIDATE_COMMIT,
                'integrity' => 'PASS',
            ]);
        });
        $this->mock(ProductionReadOnlyAudit::class, function (MockInterface $mock): void {
            $mock->shouldReceive('collect')->once()->andThrow(new \RuntimeException(
                'raw-exception DB_USERNAME=secret-user DB_PASSWORD=secret-password'
            ));
        });

        $exit = Artisan::call('release:audit-r0', [
            '--confirm-read-only' => 'IR1-R0-READ-ONLY',
            '--bundle-manifest' => 'synthetic-manifest.json',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('R0_APPLICATION_AUDIT_FAILED', $output);
        $this->assertStringContainsString('"failure_stage": "application_audit"', $output);
        $this->assertStringContainsString('"evidence_completeness": "incomplete"', $output);
        $this->assertStringNotContainsString('raw-exception', $output);
        $this->assertStringNotContainsString('secret-user', $output);
        $this->assertStringNotContainsString('secret-password', $output);
    }

    public function test_host_audit_has_no_file_or_symlink_mutation_and_sanitizes_paths(): void
    {
        $root = storage_path('framework/testing/r0-host-audit');
        if (! is_dir($root)) {
            mkdir($root, 0775, true);
        }
        $current = $root.'/current-target';
        $shared = $root.'/shared';
        $backup = $root.'/backup';
        foreach ([$current, $shared, $backup] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
        file_put_contents($backup.'/synthetic-backup.bin', 'synthetic');

        $script = base_path('deployment/r0-audit/r0-host-audit.php');
        $manifest = $root.'/r0-bundle-manifest.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => 1,
            'output_schema_version' => 2,
            'source_commit' => R0AuditBundleVerifier::EXACT_CANDIDATE_COMMIT,
            'bundle_id' => str_repeat('a', 64),
            'critical_files' => [
                'deployment/r0-audit/r0-host-audit.php' => hash_file('sha256', $script),
            ],
        ], JSON_THROW_ON_ERROR));

        $before = R0BundleHash::tree($root);
        $process = new Process([
            PHP_BINARY,
            $script,
            '--bundle-manifest='.$manifest,
            '--current-link='.$current,
            '--previous-link='.$root.'/current.previous',
            '--shared-root='.$shared,
            '--backup-root='.$backup,
        ], base_path());
        $process->mustRun();
        $after = R0BundleHash::tree($root);
        $output = $process->getOutput();

        $this->assertSame($before, $after);
        $this->assertStringContainsString('"status": "PASS"', $output);
        $this->assertStringContainsString(hash('sha256', str_replace('\\', '/', $current)), $output);
        $this->assertStringNotContainsString(str_replace('\\', '/', $current), str_replace('\\', '/', $output));
        $this->assertStringNotContainsString('synthetic-backup.bin', $output);

        $source = file_get_contents($script);
        foreach (['file_put_contents(', 'unlink(', 'rename(', 'chmod(', 'chown(', 'symlink(', 'mkdir(', 'touch('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_bundle_builder_is_pinned_to_exact_candidate_and_excludes_later_migrations(): void
    {
        $builder = file_get_contents(base_path('deployment/build-r0-audit-bundle.sh'));
        $this->assertStringContainsString(
            'candidate_commit="'.R0AuditBundleVerifier::EXACT_CANDIDATE_COMMIT.'"',
            $builder,
        );
        $this->assertStringContainsString('git -C "${repo_root}" archive --format=tar "${candidate_commit}"', $builder);
        $this->assertStringNotContainsString('git archive --format=tar HEAD', $builder);

        $process = new Process([
            'git', 'ls-tree', '-r', '--name-only',
            R0AuditBundleVerifier::EXACT_CANDIDATE_COMMIT,
            '--', 'database/migrations',
        ], base_path());
        $process->mustRun();
        $migrations = array_values(array_filter(preg_split('/\R/', trim($process->getOutput())) ?: []));

        $this->assertCount(94, $migrations);
        $this->assertSame([], array_values(array_filter(
            $migrations,
            static fn (string $path): bool => str_contains($path, 'ai_common')
                || str_contains($path, 'realtime')
                || str_contains($path, 'management_design'),
        )));
    }
}
