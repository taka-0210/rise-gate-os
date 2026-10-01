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
        $this->assertLessThanOrEqual(48, $result['sql_safety']['total_statements']);
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
            '--topology-profile=immutable-release',
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

    public function test_host_audit_supports_legacy_fixed_root_without_claiming_immutable_or_url_migration(): void
    {
        $root = storage_path('framework/testing/r0-host-audit-legacy');
        $siteRoot = $root.'/rise-gate.com';
        $application = $siteRoot.'/rise-gate-os';
        $publicContainer = $siteRoot.'/public_html';
        $public = $publicContainer.'/os.rise-gate.com';
        $staging = $root.'/.rise-gate-os-deploy';
        $backup = $publicContainer.'/_backup';
        foreach ([$application, $application.'/storage', $public, $staging, $backup] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
        file_put_contents($application.'/.env', 'APP_KEY=legacy-secret-output-canary');
        file_put_contents($public.'/index.php', <<<'PHP'
<?php
$applicationRoot = dirname(__DIR__, 2).'/rise-gate-os';
PHP);
        $legacyCommit = '3e5b6b3c613f5bc41892da6613fcc323306f95fc';
        $legacyMarker = $publicContainer.'/.rise-gate-deploy-revision';
        file_put_contents($legacyMarker, $legacyCommit.PHP_EOL);
        file_put_contents($backup.'/private-backup-name-canary.bin', 'synthetic');

        $script = base_path('deployment/r0-audit/r0-host-audit.php');
        $manifest = $root.'/r0-bundle-manifest.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => 1,
            'output_schema_version' => 2,
            'source_commit' => R0AuditBundleVerifier::EXACT_CANDIDATE_COMMIT,
            'bundle_id' => str_repeat('b', 64),
            'critical_files' => [
                'deployment/r0-audit/r0-host-audit.php' => hash_file('sha256', $script),
            ],
        ], JSON_THROW_ON_ERROR));

        $before = R0BundleHash::tree($root);
        $process = new Process([
            PHP_BINARY,
            $script,
            '--bundle-manifest='.$manifest,
            '--topology-profile=legacy-fixed-root',
            '--application-root='.$application,
            '--public-root='.$public,
            '--legacy-revision-marker='.$legacyMarker,
            '--legacy-staging-root='.$staging,
            '--backup-root='.$backup,
        ], base_path());
        $process->mustRun();
        $after = R0BundleHash::tree($root);
        $output = $process->getOutput();
        $evidence = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($before, $after);
        $this->assertSame('PASS', $evidence['status']);
        $this->assertSame('legacy-fixed-root', $evidence['evidence']['topology']['profile']);
        $this->assertTrue($evidence['evidence']['topology']['legacy_public_bridge']['legacy_bridge_contract_matches']);
        $this->assertTrue($evidence['evidence']['topology']['legacy_public_bridge']['derived_application_root_matches']);
        $this->assertSame('UNSUPPORTED', $evidence['evidence']['filesystem']['current']['status']);
        $this->assertSame('UNSUPPORTED', $evidence['evidence']['filesystem']['shared']['status']);
        $this->assertSame($legacyCommit, $evidence['evidence']['release_marker']['commit_sha']);
        $this->assertFalse($evidence['evidence']['release_marker']['matches_ir1_candidate']);
        $this->assertSame('UNKNOWN', $evidence['evidence']['release_marker']['application_scope_binding']);
        $this->assertSame('OUT_OF_SCOPE', $evidence['evidence']['capabilities']['application_production_url_migration']);
        $this->assertSame('UNKNOWN', $evidence['evidence']['topology']['public_backup_exposure']);
        $this->assertStringNotContainsString('legacy-secret-output-canary', $output);
        $this->assertStringNotContainsString('private-backup-name-canary.bin', $output);
        $this->assertStringNotContainsString(str_replace('\\', '/', $root), str_replace('\\', '/', $output));

        file_put_contents($legacyMarker, 'raw-invalid-marker-secret-canary');
        $beforeInvalid = R0BundleHash::tree($root);
        $invalidProcess = new Process([
            PHP_BINARY,
            $script,
            '--bundle-manifest='.$manifest,
            '--topology-profile=legacy-fixed-root',
            '--application-root='.$application,
            '--public-root='.$public,
            '--legacy-revision-marker='.$legacyMarker,
            '--legacy-staging-root='.$staging,
            '--backup-root='.$backup,
        ], base_path());
        $invalidProcess->mustRun();
        $invalidOutput = $invalidProcess->getOutput();
        $invalidEvidence = json_decode($invalidOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($beforeInvalid, R0BundleHash::tree($root));
        $this->assertSame('UNKNOWN', $invalidEvidence['evidence']['release_marker']['status']);
        $this->assertSame('legacy_release_marker_invalid', $invalidEvidence['evidence']['release_marker']['reason']);
        $this->assertStringNotContainsString('raw-invalid-marker-secret-canary', $invalidOutput);
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
