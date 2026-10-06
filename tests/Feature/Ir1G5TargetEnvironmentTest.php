<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class Ir1G5TargetEnvironmentTest extends TestCase
{
    private const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    public function test_contract_separates_legacy_service_site_and_application_target(): void
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('deployment/g5-target-environment/target-contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('company-os.ir1.g5-target-environment.v1', $contract['contract_id']);
        $this->assertSame(self::CANDIDATE, $contract['source_commit']);
        $this->assertSame('app.company-os.jp', $contract['production_urls']['application_target']);
        $this->assertSame('os.rise-gate.com', $contract['production_urls']['legacy_application']);
        $this->assertSame('company-os.jp/company-os-app', $contract['relative_paths']['target_topology_root']);
        $this->assertSame('company-os.jp/public_html/app.company-os.jp', $contract['relative_paths']['target_public_entry']);
        $this->assertSame('company-os.jp/company-os-app/current/public', $contract['relative_paths']['target_public_entry_link']);
        $this->assertSame('rise-gate.com/', $contract['path_boundaries']['legacy_prefix_read_only']);
        $this->assertSame('company-os.jp/', $contract['path_boundaries']['target_mutation_prefix']);
        $this->assertFalse($contract['production_mutation_authorized']);
        $this->assertCount(9, $contract['continuing_blockers']);
    }

    public function test_read_only_inspector_has_no_remote_mutation_or_secret_read(): void
    {
        $script = (string) file_get_contents(base_path('deployment/g5-target-environment/inspect-target-read-only.sh'));

        foreach (['path_state', 'entry_count', 'domain_public_same_device', 'requires_mutating_rehearsal',
            'none_read_only_target_inspection', 'app.company-os.jp'] as $required) {
            $this->assertStringContainsString($required, $script);
        }
        foreach (['mkdir -', 'rm -', 'ln -s', 'mv -', 'chmod ', 'chown ', 'cat "$HOME', 'source "$HOME'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
    }

    public function test_rehearsal_is_isolated_cleanup_bound_and_database_free(): void
    {
        $script = (string) file_get_contents(base_path('deployment/g5-target-environment/rehearse-posix-capabilities.sh'));

        $this->assertStringContainsString('.ir1-g5-capability-'.self::CANDIDATE, $script);
        $this->assertStringContainsString('TARGET_PATHS_DIFFERENT_FILESYSTEM', $script);
        $this->assertStringContainsString('mv -Tf', $script);
        $this->assertStringContainsString('chmod 0600', $script);
        $this->assertStringContainsString('cleanup_state=complete', $script);
        $this->assertStringContainsString('residual_entry_count=0', $script);
        $this->assertStringContainsString('target_topology_changed=false', $script);
        $this->assertStringContainsString('target_public_entry_changed=false', $script);
        $this->assertStringContainsString('env_changed=false', $script);
        $this->assertStringContainsString('shared_storage_changed=false', $script);
        $this->assertStringContainsString('legacy_production_changed=false', $script);
        $this->assertStringContainsString("EXPECTED_HOME='/home/xs377816'", $script);
        $this->assertStringContainsString("CREATED_ROOT='no'", $script);
        $this->assertStringContainsString('if test "$CREATED_ROOT" != \'yes\'', $script);
        $this->assertStringContainsString('PUBLIC_ENTRY_SNAPSHOT_BEFORE', $script);
        $this->assertStringContainsString('PUBLIC_ENTRY_SNAPSHOT_AFTER', $script);
        $this->assertStringNotContainsString('rise-gate.com', $script);
        $this->assertStringNotContainsString('$REHEARSAL_ROOT/shared', $script);
        $this->assertStringNotContainsString('.env.fixture', $script);
        $this->assertStringNotContainsString('mysql', $script);
        $this->assertStringNotContainsString('artisan', $script);
    }

    public function test_boundary_failure_simulation_preserves_legacy_and_public_entry(): void
    {
        $process = new Process([PHP_BINARY, base_path('deployment/g5-target-environment/simulate-g5-boundaries.php')], base_path());
        $process->mustRun();

        $this->assertStringContainsString('G5_BOUNDARY_SIMULATION=PASS', $process->getOutput());
        $this->assertStringContainsString('scenario_count=7', $process->getOutput());
        $this->assertStringContainsString('assertion_count=15', $process->getOutput());
        $this->assertStringContainsString('legacy_mutation=0', $process->getOutput());
        $this->assertStringContainsString('production_connection=false', $process->getOutput());
    }

    public function test_helper_is_one_command_one_shot_and_package_bound(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5TargetEnvironment.ps1'));

        foreach ([
            'f89a71cbfe453b2e9bd74d20fdf5e3bab9c2b98e28775ddf755922713ca8a232',
            "ValidateSet('Inspect','Rehearse')",
            'STEP_RETRY_FORBIDDEN',
            'READ_ONLY_INSPECTION_REQUIRED',
            'CAPABILITY_REHEARSAL_NOT_ELIGIBLE',
            'StrictHostKeyChecking=yes',
            'production_connection_attempted=false',
            'production_mutation=false',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
        $this->assertStringNotContainsString('scp.exe', $helper);
        $this->assertStringNotContainsString('Invoke-WebRequest', $helper);
    }

    public function test_helper_uses_powershell_51_safe_line_ending_normalization_on_the_human_path(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5TargetEnvironment.ps1'));

        $this->assertStringContainsString('g5-discovery-corrective-2', $helper);
        $this->assertStringContainsString('function Read-LfScript', $helper);
        $this->assertStringContainsString('$text.Replace($crlf,$lf).Replace($cr,$lf)', $helper);
        $this->assertStringContainsString('human_execution_path=validated_through_native_capture', $helper);
        $this->assertStringContainsString('incremental_state_contract=PASS', $helper);
        $this->assertStringContainsString('[Diagnostics.ProcessStartInfo]::new()', $helper);
        $this->assertStringContainsString('[Text.UTF8Encoding]::new($false).GetBytes($InputText)', $helper);
        $this->assertStringContainsString('read-only-inspection-corrective-2-state.json', $helper);
        $this->assertStringContainsString('REMOTE_RESULT_CAPTURED', $helper);
        $this->assertStringContainsString('stdout_bytes', $helper);
        $this->assertStringContainsString('stderr_bytes', $helper);
        $this->assertStringContainsString('local_exception_sha256', $helper);
        $this->assertStringContainsString("production_mutation_possible=(\$Step-eq'Rehearse')", $helper);
        $this->assertStringContainsString("return 'UNEXPECTED_LOCAL_FAILURE'", $helper);
        $this->assertStringContainsString('$safeCode=Get-SafeErrorCode $_.Exception', $helper);
        $this->assertStringNotContainsString('.Replace(([char]13+[char]10),[char]10)', $helper);
        $this->assertStringNotContainsString('safe_error_code=$($_.Exception.Message)', $helper);
        $this->assertStringNotContainsString('$InputText|& $File', $helper);

        $preparation = strpos($helper, '$scriptText=Read-LfScript');
        $verifyExit = strpos($helper, 'if($VerifyOnly)');
        $productionStage = strpos($helper, '$FailureStage=if($Step-eq\'Inspect\')');
        $connectionAttempt = strpos($helper, '$ConnectionAttempted=$true');

        $this->assertIsInt($preparation);
        $this->assertIsInt($verifyExit);
        $this->assertIsInt($productionStage);
        $this->assertIsInt($connectionAttempt);
        $this->assertLessThan($verifyExit, $preparation);
        $this->assertLessThan($connectionAttempt, $productionStage);
    }

    public function test_builder_is_g4_bound_deterministic_and_production_free(): void
    {
        $builder = (string) file_get_contents(base_path('deployment/g5-target-environment/Build-G5TargetPackage.ps1'));

        $this->assertStringContainsString('G4_PACKAGE_BINDING_MISMATCH', $builder);
        $this->assertStringContainsString('DETERMINISTIC_BUILD_MISMATCH', $builder);
        $this->assertStringContainsString('G5_TARGET_PACKAGE_VERIFY_ONLY=PASS', $builder);
        $this->assertStringContainsString('connection_attempted=$false', $builder);
        $this->assertStringContainsString('mutation=$false', $builder);
        $this->assertStringNotContainsString('ssh ', $builder);
        $this->assertStringNotContainsString('scp ', $builder);
    }

    public function test_g5b_target_anchor_discovery_is_read_only_and_rebinds_actual_home(): void
    {
        $script = (string) file_get_contents(base_path('deployment/g5-target-environment/inspect-target-anchor-read-only.sh'));

        foreach ([
            'IR1-G5B-READ-ONLY-TARGET-DISCOVERY',
            'actual_home_not_assumed',
            'app.company-os.jp',
            'target_domain_owner_uid',
            'target_public_entry_mode',
            'legacy_physical_separation',
            'target_filesystem_type',
            'default_index_sha256',
            'posix_capability_rehearsal=not_executed',
            'production_mutation=false',
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }
        foreach (['mkdir ', 'rm ', 'rmdir ', 'mv ', 'cp ', 'ln ', 'chmod ', 'chown ', 'touch ', 'mysql', 'artisan'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
    }

    public function test_g5b_helper_is_one_shot_host_key_bound_and_never_rehearses(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5BReadOnlyTargetDiscovery.ps1'));

        foreach ([
            'g5b-target-discovery-v1',
            'g5b-target-discovery-corrective-1',
            'G5B_RETRY_FORBIDDEN',
            'INITIAL_STOP_STATE_REQUIRED',
            'sanitized-target-observation.json',
            'target_binding_mismatches',
            'StrictHostKeyChecking=yes',
            'HostKeyAlgorithms=ssh-ed25519',
            'SSH_IDENTITY_FINGERPRINT_MISMATCH',
            'HOST_KEY_FINGERPRINT_MISMATCH',
            'actual_home_not_assumed',
            'posix_capability_rehearsal=not_executed',
            'production_mutation=false',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
        $this->assertStringNotContainsString('rehearse-posix-capabilities.sh', $helper);
        $this->assertStringNotContainsString('scp.exe', $helper);
    }

    public function test_g5b_new_target_profile_is_explicitly_bound_and_does_not_reuse_legacy_alias(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5BReadOnlyTargetDiscovery.ps1'));

        foreach ([
            "ValidateSet('Initial', 'Corrective1', 'NewTarget')",
            'g5b-new-target-discovery-v1',
            'sv17169.xserver.jp',
            'xs377816',
            'codex-company-os-target-production',
            'SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g',
            'SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8',
            'ba34cd1acb3c594d282a6c78a4352971f9f4831097c30f4423f6a326ceaa8983',
            'UserKnownHostsFile=$KnownHostsPath',
            "binding=if (\$IsNewTarget) { 'explicit_new_target' }",
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
    }

    public function test_g5b_cross_server_reconciliation_is_candidate_bound_and_production_free(): void
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('deployment/g5-target-environment/g5b-cross-server-reconciliation-contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $script = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5BCrossServerReconciliation.ps1'));

        $this->assertSame('company-os.ir1.g5b-cross-server-reconciliation.v1', $contract['contract_id']);
        $this->assertSame(self::CANDIDATE, $contract['candidate']);
        $this->assertSame('unknown', $contract['required_disposition']['remote_legacy_physical_separation']);
        $this->assertSame('PASS', $contract['required_disposition']['cross_server_identity']);
        $this->assertSame('yes', $contract['required_disposition']['effective_legacy_separation']);
        $this->assertSame('cross_server_trusted_host_identity', $contract['required_disposition']['separation_basis']);
        $this->assertSame('PASS', $contract['required_disposition']['target_anchor_binding']);
        $this->assertSame('PASS', $contract['required_disposition']['VHOST_DOCUMENT_ROOT_BINDING']);
        $this->assertSame(
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            $contract['required_disposition']['PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION'],
        );
        $this->assertFalse($contract['safety']['production_connection_authorized']);
        $this->assertFalse($contract['safety']['ssh_connection_authorized']);
        $this->assertFalse($contract['safety']['http_request_authorized']);
        $this->assertFalse($contract['safety']['filesystem_mutation_authorized']);
        $this->assertCount(9, $contract['inputs']);
        $this->assertCount(3, $contract['management_plane_attestation']['entries']);

        foreach ([
            'production_free_local_evidence_reconciliation',
            'INPUT_EVIDENCE_HASH_MISMATCH',
            'CROSS_SERVER_HOST_NOT_DISTINCT',
            'CROSS_SERVER_HOST_KEY_NOT_DISTINCT',
            'LEGACY_PATH_PRESENT_ON_NEW_TARGET',
            'xserver_management_plane_triangulation',
            'G5-B RECONCILED FORMAL PASS / G5-C POSIX CAPABILITY REHEARSAL READY',
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            'production_connection_attempted = $false',
            'ssh_connection_attempted = $false',
            'http_request_attempted = $false',
            'g5_c_posix_capability_rehearsal=not_executed',
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }
        foreach (['ssh.exe', 'Invoke-WebRequest', 'curl.exe', 'rehearse-posix-capabilities.sh'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
    }

    public function test_g5c_new_target_helper_is_receipt_bound_explicit_and_one_shot(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5CNewTargetPosixRehearsal.ps1'));

        foreach ([
            'g5c-new-target-posix-rehearsal-v1',
            '8d8ea110d0d7069af519f8c9cab8240891a050e7f366a620f5efc9fe86d852f3',
            'ec24b30e62c34564aaaaccfa928edbac98f95e7e4748594422b641f4bbbae7c3',
            'G5-B RECONCILED FORMAL PASS / G5-C POSIX CAPABILITY REHEARSAL READY',
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            'sv17169.xserver.jp',
            'xs377816',
            'codex-company-os-target-production',
            'SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g',
            'SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8',
            'HostKeyAlgorithms=ssh-ed25519',
            'StrictHostKeyChecking=yes',
            'ConnectionAttempts=1',
            'G5C_ATTEMPT_ALREADY_RECORDED',
            'candidate_bound_isolated_rehearsal_completed_and_cleaned',
            'deploy_authorized=false',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }

        $this->assertStringNotContainsString("'company-os-production'", $helper);
        $this->assertStringNotContainsString('sv17033.xserver.jp', $helper);
        $this->assertStringNotContainsString('scp.exe', $helper);
        $this->assertStringNotContainsString('Invoke-WebRequest', $helper);
        $this->assertStringNotContainsString('curl.exe', $helper);
        $this->assertStringNotContainsString('mysql', $helper);
        $this->assertStringNotContainsString('artisan', $helper);
    }

    public function test_g5c_remote_pass_requires_cleanup_and_protected_boundaries_unchanged(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5CNewTargetPosixRehearsal.ps1'));

        foreach ([
            "cleanup_state='complete'",
            "residual_entry_count='0'",
            "target_topology_changed='false'",
            "target_public_entry_changed='false'",
            "legacy_production_changed='false'",
            "env_changed='false'",
            "shared_storage_changed='false'",
            "database_connection='not_attempted'",
            "dns_ssl_change='not_attempted'",
            "deploy='not_attempted'",
            "retry_available='false'",
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
    }
}
