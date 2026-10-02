<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class Ir1G4ImmutableTopologyPackageTest extends TestCase
{
    private const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    public function test_topology_contract_is_exact_and_carries_every_open_blocker(): void
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('deployment/g4-topology/topology-contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('company-os.ir1.g4-immutable-topology.v1', $contract['contract_id']);
        $this->assertSame('ir1-'.self::CANDIDATE, $contract['release_id']);
        $this->assertSame(self::CANDIDATE, $contract['source_commit']);
        $this->assertSame('7d979ef6a854bce7943740044a3f0c4d942dc617', $contract['source_tree']);
        $this->assertSame('2de9b840627d0dbfd1beabaca7e8609dc9e16be2fd9c3021e3dfdc2c764cdb69', $contract['artifact']['sha256']);
        $this->assertSame(5807985, $contract['artifact']['bytes']);
        $this->assertSame('releases/<release-id>/', $contract['topology']['releases']);
        $this->assertSame('shared/.env', $contract['topology']['shared_env']);
        $this->assertSame('shared/storage/', $contract['topology']['shared_storage']);
        $this->assertSame('current/public', $contract['topology']['public_entry_target']);
        $this->assertTrue($contract['switch_contract']['atomic_rename']);
        $this->assertFalse($contract['switch_contract']['database_rollback']);
        $this->assertSame('workflow_dispatch_only', $contract['workflow']['trigger']);
        $this->assertFalse($contract['production_mutation_authorized']);
        $this->assertSame([
            'usable_backup_unknown',
            'db_restore_readiness_blocker',
            'release_marker_application_binding_blocker',
            'env_permission_0604_hardening_blocker',
            'user_cron_unknown',
            'external_writer_enablement_unknown',
            'active_transaction_metadata_lock_unsupported',
            'database_application_collation_difference',
        ], $contract['continuing_blockers']);
    }

    public function test_operator_scripts_separate_install_switch_verify_and_code_rollback(): void
    {
        $install = (string) file_get_contents(base_path('deployment/g4-topology/install-release.sh'));
        $switch = (string) file_get_contents(base_path('deployment/g4-topology/switch-release.sh'));
        $rollback = (string) file_get_contents(base_path('deployment/g4-topology/rollback-release.sh'));
        $verify = (string) file_get_contents(base_path('deployment/g4-topology/verify-topology.sh'));

        $this->assertStringContainsString('ARTIFACT_SHA256_MISMATCH', $install);
        $this->assertStringContainsString('RELEASE_ALREADY_EXISTS', $install);
        $this->assertStringContainsString('current_changed=false', $install);
        $this->assertStringNotContainsString('current.previous', $install);

        $this->assertStringContainsString('mv -Tf "$previous_next" "$topology_root/current.previous"', $switch);
        $this->assertStringContainsString('mv -Tf "$next_link" "$topology_root/current"', $switch);
        $this->assertStringContainsString('CURRENT_SYMLINK_REQUIRED', $switch);
        $this->assertStringContainsString('PREVIOUS_NOT_SYMLINK', $switch);

        $this->assertStringContainsString('CURRENT_IS_NOT_EXACT_CANDIDATE', $rollback);
        $this->assertStringContainsString('database_rollback_performed=false', $rollback);
        $this->assertStringContainsString('additive_schema_retained=true', $rollback);

        $this->assertStringContainsString('production_change_scope=none_read_only_verification', $verify);
        foreach ([$install, $switch, $rollback, $verify] as $script) {
            $this->assertStringNotContainsString("\r", $script);
            $this->assertStringNotContainsString('php artisan migrate', $script);
            $this->assertStringNotContainsString('mysql ', $script);
            $this->assertStringNotContainsString('ssh ', $script);
            $this->assertStringNotContainsString('curl ', $script);
        }
    }

    public function test_builder_is_candidate_bound_deterministic_and_production_free(): void
    {
        $builder = (string) file_get_contents(base_path('deployment/g4-topology/Build-G4TopologyPackage.ps1'));

        foreach ([
            self::CANDIDATE,
            'G3_BINDING_MISMATCH',
            'DETERMINISTIC_PACKAGE_MISMATCH',
            'WORKFLOW_TRIGGER_CONTRACT_FAILED',
            'G4_TOPOLOGY_PACKAGE_VERIFY_ONLY=PASS',
            'g4_verify_package',
            'package_runtime_contract',
            '$Utf8NoBom',
            'real_posix_symlink_rehearsal',
            'connection_attempted = $false',
            'mutation = $false',
        ] as $required) {
            $this->assertStringContainsString($required, $builder);
        }
        foreach (['ssh ', 'scp ', 'sftp ', 'Invoke-WebRequest', 'migrate --force'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $builder);
        }
    }

    public function test_failure_injection_simulation_preserves_public_current_boundary(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('deployment/g4-topology/simulate-topology.php'),
        ], base_path());
        $process->mustRun();

        $this->assertStringContainsString('G4_TOPOLOGY_SIMULATION=PASS', $process->getOutput());
        $this->assertStringContainsString('scenario_count=7', $process->getOutput());
        $this->assertStringContainsString('assertion_count=22', $process->getOutput());
        $this->assertStringContainsString('production_connection=false', $process->getOutput());
        $this->assertStringContainsString('production_mutation=false', $process->getOutput());
    }

    public function test_release_workflows_remain_manual_only(): void
    {
        foreach ([
            '.github/workflows/build-release-candidate.yml',
            '.github/workflows/deploy-production.yml',
        ] as $relative) {
            $workflow = (string) file_get_contents(base_path($relative));
            $this->assertMatchesRegularExpression('/^  workflow_dispatch:\s*$/m', $workflow);
            $this->assertDoesNotMatchRegularExpression('/^  (push|pull_request|schedule):\s*$/m', $workflow);
        }
    }
}
