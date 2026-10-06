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
            'g5c-new-target-posix-rehearsal-corrective-1',
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
            "[ValidateSet('Initial', 'Corrective1')]",
            '81a82f1fa237fc3012ae7a6fa60bd854b3100c42947df01f3f0d8b650b099b96',
            'e3defa570f9f361812522715e15b702be37b11532bbb1ff63bd9215ec4fabd09',
            'production-g5c-new-target-rehearsal-corrective-1-$Candidate',
            'Assert-InitialAttemptEvidence',
            'human_approved_corrective_retry',
            'initial_attempt_evidence_immutable',
            'corrective_retry_number',
            '[IO.File]::Replace($temporaryPath, $Path, $backupPath)',
            '[IO.File]::Move($temporaryPath, $Path)',
            'G5C_LOCAL_STATE_PERSISTENCE_CORRECTIVE_VERIFY_ONLY=PASS',
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
        $this->assertStringNotContainsString('Move-Item -LiteralPath $temporaryPath -Destination $Path', $helper);

        $processStart = strpos($helper, 'if (-not $process.Start())');
        $connectionObserved = strpos($helper, '$script:ConnectionAttempted = $true');
        $remoteInvoke = strpos($helper, '$result = Invoke-CapturedProcess $ssh.Source $arguments $scriptText');
        $this->assertIsInt($processStart);
        $this->assertIsInt($connectionObserved);
        $this->assertIsInt($remoteInvoke);
        $this->assertLessThan($connectionObserved, $processStart);
        $this->assertLessThan($remoteInvoke, $connectionObserved);
    }

    public function test_g5c_corrective_retry_preserves_initial_evidence_and_uses_separate_one_shot_root(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5CNewTargetPosixRehearsal.ps1'));

        $initialRoot = strpos($helper, '$InitialEvidenceRoot = Join-Path $Root "storage\\app\\release-audit\\production-g5c-new-target-rehearsal-$Candidate"');
        $correctiveRoot = strpos($helper, '$CorrectiveEvidenceRoot = Join-Path $Root "storage\\app\\release-audit\\production-g5c-new-target-rehearsal-corrective-1-$Candidate"');
        $selectedRoot = strpos($helper, '$EvidenceRoot = if ($IsCorrectiveRetry) { $CorrectiveEvidenceRoot } else { $InitialEvidenceRoot }');
        $attemptGuard = strpos($helper, "Stop-G5C 'G5C_ATTEMPT_ALREADY_RECORDED'");
        $remoteInvoke = strpos($helper, '$result = Invoke-CapturedProcess $ssh.Source $arguments $scriptText');

        $this->assertIsInt($initialRoot);
        $this->assertIsInt($correctiveRoot);
        $this->assertIsInt($selectedRoot);
        $this->assertIsInt($attemptGuard);
        $this->assertIsInt($remoteInvoke);
        $this->assertLessThan($correctiveRoot, $initialRoot);
        $this->assertLessThan($selectedRoot, $correctiveRoot);
        $this->assertLessThan($remoteInvoke, $attemptGuard);

        foreach ([
            'INITIAL_ATTEMPT_EVIDENCE_ENTRY_MISMATCH',
            'INITIAL_ATTEMPT_EVIDENCE_HASH_MISMATCH',
            'INITIAL_ATTEMPT_STATE_MISMATCH',
            'INITIAL_ATTEMPT_TEMPORARY_STATE_MISMATCH',
            'initial_state_sha256=$(if ($IsCorrectiveRetry)',
            'initial_temporary_state_sha256=$(if ($IsCorrectiveRetry)',
            'blind_retry=$false',
            "Write-Output 'retry_available=false'",
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
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

    public function test_g5c_local_state_persistence_corrective_uses_real_helper_path_without_connection(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The G5-C Human helper runs under Windows PowerShell.');
        }

        $helper = base_path('deployment/g5-target-environment/Invoke-G5CNewTargetPosixRehearsal.ps1');
        $process = new Process([
            'powershell.exe',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $helper,
            '-VerifyPersistenceOnly',
        ], base_path());
        $process->setTimeout(30);
        $process->mustRun();

        $output = $process->getOutput();
        $this->assertStringContainsString('G5C_LOCAL_STATE_PERSISTENCE_CORRECTIVE_VERIFY_ONLY=PASS', $output);
        $this->assertStringContainsString('generation_count=3', $output);
        $this->assertStringContainsString('atomic_initial_move=PASS', $output);
        $this->assertStringContainsString('atomic_existing_replace=PASS', $output);
        $this->assertStringContainsString('tmp_residual=0', $output);
        $this->assertStringContainsString('backup_residual=0', $output);
        $this->assertStringContainsString('production_connection_attempted=false', $output);
        $this->assertStringContainsString('production_mutation=false', $output);
        $this->assertStringContainsString('existing_g5c_evidence_modified=false', $output);
        $this->assertStringContainsString('g5c_posix_capability_rehearsal=not_executed', $output);
    }

    public function test_g5_target_skeleton_contract_creates_only_three_empty_directories(): void
    {
        $contract = json_decode((string) file_get_contents(
            base_path('deployment/g5-target-environment/target-skeleton-contract.json')
        ), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('company-os.ir1.g5-target-skeleton.v1', $contract['contract_id']);
        $this->assertSame('924af91188cc60d33ff87c91b94ecc1d539566e6', $contract['candidate']);
        $this->assertSame([
            'company-os.jp/company-os-app',
            'company-os.jp/company-os-app/releases',
            'company-os.jp/company-os-app/shared',
        ], array_column($contract['creation_set'], 'relative_path'));
        foreach ($contract['creation_set'] as $entry) {
            $this->assertSame('directory', $entry['type']);
            $this->assertSame('0750', $entry['mode']);
            $this->assertSame(20046, $entry['owner_uid']);
            $this->assertSame(1000, $entry['group_gid']);
        }
        foreach ([
            'company-os.jp/company-os-app/shared/.env',
            'company-os.jp/company-os-app/shared/storage',
            'company-os.jp/company-os-app/current',
            'company-os.jp/company-os-app/current.previous',
            'company-os.jp/public_html/app.company-os.jp',
            'application placement',
            'migration',
            'release marker binding',
            'dns',
            'ssl',
            'deploy',
        ] as $excluded) {
            $this->assertContains($excluded, $contract['excluded_set']);
        }
        $this->assertFalse($contract['public_entry_guard']['content_read']);
        $this->assertFalse($contract['public_entry_guard']['mutation_allowed']);
        $this->assertSame('PENDING_G5_PUBLIC_ENTRY_GATE', $contract['public_entry_guard']['disposition']);
        $this->assertTrue($contract['operation']['at_most_once']);
        $this->assertFalse($contract['operation']['blind_retry']);
        $this->assertFalse($contract['operation']['success_replay']);
        $this->assertFalse($contract['cleanup_and_rollback']['recursive_delete']);
        $this->assertSame('SEPARATE_HUMAN_GATE', $contract['cleanup_and_rollback']['post_pass_rollback']);
        $this->assertFalse($contract['production_mutation_authorized']);
    }

    public function test_g5_target_skeleton_remote_script_is_exact_atomic_and_fail_closed(): void
    {
        $script = (string) file_get_contents(base_path('deployment/g5-target-environment/build-target-skeleton.sh'));

        foreach ([
            "EXPECTED_CONFIRM='IR1-G5-TARGET-SKELETON-BUILD'",
            "EXPECTED_HOME='/home/xs377816'",
            "EXPECTED_UID='20046'",
            "EXPECTED_GID='1000'",
            'TARGET_TOPOLOGY_ROOT="$TARGET_DOMAIN/company-os-app"',
            'mkdir -m 0750 -- "$STAGING_ROOT"',
            'mkdir -m 0750 -- "$STAGING_ROOT/releases" "$STAGING_ROOT/shared"',
            'mv -T -- "$STAGING_ROOT" "$TARGET_TOPOLOGY_ROOT"',
            'rmdir -- "$STAGING_ROOT/shared" "$STAGING_ROOT/releases" "$STAGING_ROOT"',
            'rmdir -- "$TARGET_TOPOLOGY_ROOT/shared" "$TARGET_TOPOLOGY_ROOT/releases" "$TARGET_TOPOLOGY_ROOT"',
            "test ! -e \"\$TARGET_TOPOLOGY_ROOT/shared/.env\"",
            "test ! -e \"\$TARGET_TOPOLOGY_ROOT/shared/storage\"",
            "test ! -e \"\$TARGET_TOPOLOGY_ROOT/current\"",
            "test ! -e \"\$TARGET_TOPOLOGY_ROOT/current.previous\"",
            "'target_public_entry_changed=false'",
            "'env_created=false'",
            "'shared_storage_created=false'",
            "'application_release_created=false'",
            "'migration=not_attempted'",
            "'release_marker_binding=not_attempted'",
            "'dns_ssl_change=not_attempted'",
            "'deploy=not_attempted'",
            "'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE'",
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }
        foreach (['rm -rf', 'unlink ', 'ln -s', 'chmod ', 'chown ', 'mysql', 'artisan', 'curl ', 'wget ', 'scp '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
    }

    public function test_g5_target_skeleton_helper_is_g5c_bound_one_shot_and_connection_truthful(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5TargetSkeletonBuild.ps1'));

        foreach ([
            'g5-target-skeleton-build-v1',
            'de5476fbd019ab48909a4a9b82d9d12999dd21d29a0b3c4020da9d54ed728869',
            'bbd05048b982b751861c48f84d2ba2c0ecaade333dd2d55f7d71b4646e57a0ff',
            'a1dd2871236868affe3b4a9d5454dc87eb614b15f1049e3a555c3700dec59faf',
            'e696b86dbb818c85f99d0115f547408cf193eb1fd67693807a7dd9f28d2ed99c',
            'sv17169.xserver.jp',
            'xs377816',
            'SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8',
            'production-g5-target-skeleton-build-$Candidate',
            'SKELETON_BUILD_ATTEMPT_ALREADY_RECORDED',
            'G5C_FORMAL_PASS_MISMATCH',
            '[IO.File]::Replace($temporaryPath, $Path, $backupPath)',
            'exact_empty_target_skeleton_created',
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            'deploy_authorized=false',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
        foreach (['sv17033.xserver.jp', 'scp.exe', 'Invoke-WebRequest', 'curl.exe', 'mysql', 'artisan'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $helper);
        }

        $processStart = strpos($helper, 'if (-not $process.Start())');
        $connectionObserved = strpos($helper, '$script:ConnectionAttempted = $true');
        $remoteInvoke = strpos($helper, '$result = Invoke-CapturedProcess $ssh.Source $arguments $scriptText $true');
        $this->assertIsInt($processStart);
        $this->assertIsInt($connectionObserved);
        $this->assertIsInt($remoteInvoke);
        $this->assertLessThan($connectionObserved, $processStart);
        $this->assertLessThan($remoteInvoke, $connectionObserved);
    }

    public function test_g5_target_skeleton_boundary_simulation_is_production_free(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('deployment/g5-target-environment/simulate-target-skeleton.php'),
        ], base_path());
        $process->setTimeout(30);
        $process->mustRun();

        $output = $process->getOutput();
        $this->assertStringContainsString('G5_TARGET_SKELETON_SIMULATION=PASS', $output);
        $this->assertStringContainsString('scenarios=6', $output);
        $this->assertStringContainsString('assertions=13', $output);
        $this->assertStringContainsString('production_connection_attempted=false', $output);
        $this->assertStringContainsString('production_mutation=false', $output);
    }

    public function test_g5_target_skeleton_human_helper_verify_modes_do_not_connect(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The G5 Skeleton Human helper runs under Windows PowerShell.');
        }

        $helper = base_path('deployment/g5-target-environment/Invoke-G5TargetSkeletonBuild.ps1');
        foreach (['-VerifyPersistenceOnly', '-VerifyOnly'] as $mode) {
            $process = new Process([
                'powershell.exe',
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $helper,
                $mode,
            ], base_path());
            $process->setTimeout(30);
            $process->run();
            $output = $process->getOutput();
            if ($mode === '-VerifyOnly' && is_dir(base_path(
                'storage/app/release-audit/production-g5-target-skeleton-build-'.self::CANDIDATE
            ))) {
                $this->assertSame(1, $process->getExitCode());
                $this->assertStringContainsString('safe_error_code=SKELETON_BUILD_ATTEMPT_ALREADY_RECORDED', $output);
            } else {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$output);
                $this->assertStringContainsString('target_skeleton_build=not_executed', $output);
            }
            $this->assertStringContainsString('production_connection_attempted=false', $output);
            $this->assertStringContainsString('production_mutation=false', $output);
        }
    }

    public function test_g5_shared_state_contract_is_candidate_bound_and_keeps_blockers_open(): void
    {
        $contract = json_decode((string) file_get_contents(
            base_path('deployment/g5-target-environment/shared-state-contract.json')
        ), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('company-os.ir1.g5-shared-state.v1', $contract['contract_id']);
        $this->assertSame(self::CANDIDATE, $contract['candidate']);
        $this->assertSame('legacy_authoritative_shared_state_source', $contract['source']['role']);
        $this->assertTrue($contract['source']['read_only']);
        $this->assertSame('/home/xs377816/company-os.jp/company-os-app/shared/.env', $contract['target']['env_path']);
        $this->assertSame('0600', $contract['target_permission_contract']['env_mode']);
        $this->assertSame('0750', $contract['target_permission_contract']['storage_directory_mode']);
        $this->assertSame('0640', $contract['target_permission_contract']['storage_file_mode']);
        $this->assertSame(20046, $contract['target_permission_contract']['owner_uid']);
        $this->assertSame(1000, $contract['target_permission_contract']['group_gid']);
        $this->assertSame(172, $contract['candidate_environment_contract']['allowlist_count']);
        $this->assertSame(
            'd408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec',
            $contract['candidate_environment_contract']['allowlist_sha256'],
        );
        $this->assertFalse($contract['candidate_environment_contract']['secret_values_output']);
        $this->assertFalse($contract['candidate_environment_contract']['raw_source_env_stored_locally']);
        foreach ([
            'source_inspector_sha256' => 'inspect-shared-source.sh',
            'target_manager_sha256' => 'manage-shared-target.sh',
        ] as $binding => $file) {
            $this->assertSame(
                hash_file('sha256', base_path('deployment/g5-target-environment/'.$file)),
                $contract['implementation_binding'][$binding],
            );
        }
        $this->assertSame(
            '5f438829ab57f3327797f9889cd6e9287219e644f2dd0dd5b5d99b12090f9509',
            $contract['implementation_binding']['projector_sha256'],
        );
        $this->assertTrue($contract['storage_contract']['final_delta_required']);
        $this->assertSame('unknown', $contract['backup_restore_dependency']['usable_backup']);
        $this->assertSame('blocker', $contract['backup_restore_dependency']['database_restore_readiness']);
        $this->assertFalse($contract['backup_restore_dependency']['blockers_resolved_by_this_gate']);
        $this->assertSame('not_attempted', $contract['operation']['database_connection']);
        $this->assertFalse($contract['production_mutation_authorized']);
        $this->assertSame(
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            $contract['PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION'],
        );
    }

    public function test_g5_shared_state_allowlist_is_exact_frozen_candidate_environment_union(): void
    {
        $gitList = new Process(['git', 'ls-tree', '-r', '--name-only', self::CANDIDATE, 'config'], base_path());
        $gitList->mustRun();
        $paths = array_values(array_filter(preg_split('/\R/', trim($gitList->getOutput())) ?: []));
        $paths[] = '.env.example';
        $keys = [];
        foreach ($paths as $path) {
            $show = new Process(['git', 'show', self::CANDIDATE.':'.$path], base_path());
            $show->mustRun();
            $content = $show->getOutput();
            if ($path === '.env.example') {
                preg_match_all('/^\s*([A-Z][A-Z0-9_]*)\s*=/m', $content, $matches);
            } else {
                preg_match_all('/env\(\s*[\'\"]([A-Z][A-Z0-9_]*)[\'\"]/', $content, $matches);
            }
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }
        $expected = array_keys($keys);
        sort($expected, SORT_STRING);
        $actualRaw = (string) file_get_contents(base_path('deployment/g5-target-environment/shared-state-env-allowlist.txt'));
        $actual = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $actualRaw))));

        $this->assertSame($expected, $actual);
        $this->assertCount(172, $actual);
        $this->assertSame(
            'd408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec',
            hash('sha256', str_replace("\r\n", "\n", $actualRaw)),
        );
    }

    public function test_g5_shared_state_projector_inventory_never_outputs_fixture_secrets(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g5-shared-projector-'.bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        mkdir($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'nested', 0700, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'fixture.txt', 'fixture');
        $secret = 'fixture-secret-never-output';
        $env = implode("\n", [
            'APP_KEY=base64:'.$secret,
            'DB_CONNECTION=mysql',
            'DB_HOST=localhost',
            'DB_PORT=3306',
            'DB_DATABASE=fixture',
            'DB_USERNAME=fixture',
            'DB_PASSWORD='.$secret,
            'MAIL_MAILER=smtp',
            'MAIL_HOST=localhost',
            'MAIL_PORT=2525',
            'MAIL_FROM_ADDRESS=fixture@example.com',
            'MAIL_FROM_NAME=Fixture',
            'ACCOUNT_MAIL_MAILER=smtp',
            'OPENAI_API_KEY='.$secret,
            'UNKNOWN_LEGACY_ONLY='.$secret,
            '',
        ]);
        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        file_put_contents($envPath, $env);
        $allowlist = (string) file_get_contents(base_path('deployment/g5-target-environment/shared-state-env-allowlist.txt'));
        try {
            $process = new Process([
                PHP_BINARY,
                base_path('deployment/g5-target-environment/shared-state-projector.php'),
                'inspect-source',
                $envPath,
                $root.DIRECTORY_SEPARATOR.'app',
                base64_encode($allowlist),
            ], base_path());
            $process->mustRun();
            $output = $process->getOutput();
            $this->assertStringContainsString('G5_SHARED_SOURCE_INVENTORY=PASS', $output);
            $this->assertStringContainsString('candidate_allowlist_count=172', $output);
            $this->assertStringContainsString('unknown_key_count=1', $output);
            $this->assertStringContainsString('target_app_url_binding=app.company-os.jp', $output);
            $this->assertStringContainsString('target_timezone=Asia_Tokyo', $output);
            $this->assertStringContainsString('secret_output=false', $output);
            $this->assertStringNotContainsString($secret, $output);
            $this->assertStringNotContainsString('APP_KEY', $output);
            $this->assertStringNotContainsString('DB_PASSWORD', $output);
            $this->assertStringNotContainsString('OPENAI_API_KEY', $output);
        } finally {
            @unlink($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'fixture.txt');
            @rmdir($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'nested');
            @rmdir($root.DIRECTORY_SEPARATOR.'app');
            @unlink($envPath);
            @rmdir($root);
        }
    }

    public function test_g5_shared_state_initial_stop_evidence_is_exact_and_immutable(): void
    {
        $root = base_path(
            'storage/app/release-audit/production-g5-shared-state-'.self::CANDIDATE
        );
        $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));

        $this->assertSame(['execution-state.json'], $entries);
        $statePath = $root.DIRECTORY_SEPARATOR.'execution-state.json';
        $this->assertSame(
            'bb98d39709625d619d0365b57850613580237be837dcc85a863d00adfbcb248d',
            hash_file('sha256', $statePath),
        );
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('STOP', $state['status']);
        $this->assertSame('REMOTE_STATUS_MISSING', $state['safe_error_code']);
        $this->assertSame('SOURCE_INVENTORY_PROCESS_PENDING', $state['local_processing_substage']);
        $this->assertTrue($state['production_connection_attempted']);
        $this->assertTrue($state['source_connection_attempted']);
        $this->assertFalse($state['target_connection_attempted']);
        $this->assertSame(2, $state['remote_process_count']);
        $this->assertSame('false', $state['production_mutation_scope']);
        $this->assertFalse($state['raw_output_stored']);
        $this->assertFalse($state['secret_values_output']);
        $this->assertSame(4, $state['local_state_generation']);
    }

    public function test_g5_shared_state_corrective_contract_binds_initial_stop_and_current_implementation(): void
    {
        $contractPath = base_path(
            'deployment/g5-target-environment/shared-state-corrective1-contract.json'
        );
        $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('company-os.ir1.g5-shared-state-corrective1.v1', $contract['contract_id']);
        $this->assertSame(self::CANDIDATE, $contract['candidate']);
        $this->assertSame(
            'bb98d39709625d619d0365b57850613580237be837dcc85a863d00adfbcb248d',
            $contract['initial_stop_evidence']['execution_state_sha256'],
        );
        $this->assertSame('immutable', $contract['initial_stop_evidence']['preservation']);
        $this->assertSame(
            'HELPER_PROJECTOR_STDIN_OUTPUT_AND_EVIDENCE_CONTRACT',
            $contract['root_cause']['classification'],
        );
        $this->assertSame(
            'PROJECTOR_STDOUT_CONSTANT_INCOMPATIBLE_WITH_STDIN_EXECUTION',
            $contract['root_cause']['helper_invocation_defect'],
        );
        $this->assertSame('UNKNOWN', $contract['root_cause']['initial_remote_exit_code']);
        $this->assertTrue($contract['corrective']['step_stream_metadata_saved_before_parse']);
        $this->assertFalse($contract['corrective']['raw_output_stored']);
        $this->assertFalse($contract['corrective_execution_authorized']);
        $this->assertFalse($contract['production_mutation_authorized']);
        foreach ([
            'source_inspector_sha256' => 'inspect-shared-source.sh',
            'target_manager_sha256' => 'manage-shared-target.sh',
            'projector_sha256' => 'shared-state-projector.php',
        ] as $binding => $file) {
            $this->assertSame(
                hash_file('sha256', base_path('deployment/g5-target-environment/'.$file)),
                $contract['implementation_binding'][$binding],
            );
        }
        $this->assertSame(
            '1637b67d6acc95c50e2433d9e48cb21328522d357ae7468c0bb1b973fa443064',
            hash_file('sha256', $contractPath),
        );
    }

    public function test_g5_shared_state_corrective_repairs_stdin_output_and_preserves_argv_contract(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g5-shared-entrypoint-'.bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        mkdir($root.DIRECTORY_SEPARATOR.'app', 0700, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'fixture.txt', 'fixture');
        $secret = 'corrective-fixture-secret-never-output';
        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        file_put_contents($envPath, implode("\n", [
            'APP_KEY=base64:'.$secret,
            'DB_CONNECTION=mysql',
            'DB_HOST=localhost',
            'DB_PORT=3306',
            'DB_DATABASE=fixture',
            'DB_USERNAME=fixture',
            'DB_PASSWORD='.$secret,
            'MAIL_MAILER=smtp',
            'MAIL_HOST=localhost',
            'MAIL_PORT=2525',
            'MAIL_FROM_ADDRESS=fixture@example.com',
            'MAIL_FROM_NAME=Fixture',
            'ACCOUNT_MAIL_MAILER=smtp',
            'OPENAI_API_KEY='.$secret,
            '',
        ]));
        $projector = (string) file_get_contents(
            base_path('deployment/g5-target-environment/shared-state-projector.php')
        );
        $allowlist = (string) file_get_contents(
            base_path('deployment/g5-target-environment/shared-state-env-allowlist.txt')
        );
        $encodedAllowlist = base64_encode($allowlist);

        try {
            $initialProjectorProcess = new Process([
                'git', 'show',
                '622f0b016ed4aa77de2befab9b64419c578ddf2c:deployment/g5-target-environment/shared-state-projector.php',
            ], base_path());
            $initialProjectorProcess->mustRun();
            $initialProjector = $initialProjectorProcess->getOutput();
            $initial = new Process([
                PHP_BINARY, '--', 'inspect-source', $envPath,
                $root.DIRECTORY_SEPARATOR.'app', $encodedAllowlist,
            ], base_path());
            $initial->setInput($initialProjector);
            $initial->run();
            $this->assertNotSame(0, $initial->getExitCode());
            $this->assertStringNotContainsString('G5_SHARED_SOURCE_INVENTORY=', $initial->getOutput());
            $this->assertStringContainsString('Undefined constant "STDOUT"', $initial->getErrorOutput());

            $corrective = new Process([
                PHP_BINARY, '--', 'inspect-source', $envPath,
                $root.DIRECTORY_SEPARATOR.'app', $encodedAllowlist,
            ], base_path());
            $corrective->setInput($projector);
            $corrective->mustRun();
            $output = $corrective->getOutput();
            $this->assertStringContainsString('G5_SHARED_SOURCE_INVENTORY=PASS', $output);
            $this->assertStringContainsString('secret_output=false', $output);
            $this->assertSame('', $corrective->getErrorOutput());
            $this->assertStringNotContainsString($secret, $output.$corrective->getErrorOutput());
        } finally {
            @unlink($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'fixture.txt');
            @rmdir($root.DIRECTORY_SEPARATOR.'app');
            @unlink($envPath);
            @rmdir($root);
        }
    }

    public function test_g5_shared_state_remote_scripts_bound_mutation_and_preserve_public_entry(): void
    {
        $source = (string) file_get_contents(base_path('deployment/g5-target-environment/inspect-shared-source.sh'));
        $target = (string) file_get_contents(base_path('deployment/g5-target-environment/manage-shared-target.sh'));
        $projector = (string) file_get_contents(base_path('deployment/g5-target-environment/shared-state-projector.php'));

        foreach ([
            "EXPECTED_HOME='/home/xs257823'",
            "SOURCE_ENV=\"\$SOURCE_APPLICATION_ROOT/.env\"",
            'production_change_scope=none_read_only_source',
            'secret_output=false',
        ] as $required) {
            $this->assertStringContainsString($required, $source);
        }
        foreach (['mkdir ', 'rm ', 'mv ', 'chmod ', 'chown ', 'mysql', 'artisan'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        foreach ([
            "STAGING_ROOT=\"\$SHARED_ROOT/.g5-shared-state-\$CANDIDATE\"",
            'TARGET_ENV="$SHARED_ROOT/.env"',
            'TARGET_STORAGE="$SHARED_ROOT/storage"',
            'chmod 0600 -- "$STAGING_SOURCE_ENV"',
            'find "$STAGING_STORAGE" -xdev -type d -exec chmod 0750',
            'find "$STAGING_STORAGE" -xdev -type f -exec chmod 0640',
            'PUBLIC_ENTRY_CHANGED_DURING_OPERATION',
            'PUBLISHED_SHARED_STATE_RETAINED',
            'storage_final_delta_required=true',
            'db_restore_readiness=blocker',
            'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE',
        ] as $required) {
            $this->assertStringContainsString($required, $target);
        }
        foreach (['mysql', 'artisan', 'curl ', 'wget '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $target);
        }
        foreach ([
            "'APP_URL' => 'https://app.company-os.jp'",
            "'APP_ENV' => 'production'",
            "'APP_DEBUG' => 'false'",
            "'APP_TIMEZONE' => 'Asia/Tokyo'",
            "'APP_DISPLAY_TIMEZONE' => 'Asia/Tokyo'",
            "'SESSION_SECURE_COOKIE' => 'true'",
            'SOURCE_ENV_COPY_DRIFT',
            'TARGET_ENV_STAGING_COLLISION',
            'target_env_values_output=false',
        ] as $required) {
            $this->assertStringContainsString($required, $projector);
        }
    }

    public function test_g5_shared_state_helper_is_one_shot_secret_safe_and_connection_truthful(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5SharedState.ps1'));
        foreach ([
            'g5-shared-state-v1',
            'g5-shared-state-corrective-1',
            'SHARED_STATE_ATTEMPT_ALREADY_RECORDED',
            'Assert-InitialAttemptEvidence',
            'INITIAL_ATTEMPT_EVIDENCE_HASH_MISMATCH',
            'scp.exe',
            "'-3','-q'",
            'g5-legacy-source',
            'g5-new-target',
            'raw_source_env_stored_locally=$false',
            'secret_values_output=$false',
            'sourceInventory.source_env_sha256',
            '[IO.File]::Replace($temporaryPath, $Path, $backupPath)',
            'storage_final_delta_required=true',
            'db_restore_readiness=blocker',
            'application_release_binding=not_attempted',
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            'retry_available=false',
            'deploy_authorized=false',
            "'php','--','inspect-source'",
            "Record-StepMetadata 'source_inventory'",
            'step_streams=[ordered]@{}',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
        foreach (['Invoke-WebRequest', 'curl.exe', 'mysql.exe', 'php artisan migrate'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $helper);
        }
        $processStart = strpos($helper, 'if (-not $process.Start())');
        $connectionObserved = strpos($helper, '$script:ConnectionAttempted = $true');
        $sourceInvocation = strpos($helper, '$sourcePreflightResult = Invoke-Native');
        $this->assertIsInt($processStart);
        $this->assertIsInt($connectionObserved);
        $this->assertIsInt($sourceInvocation);
        $this->assertLessThan($connectionObserved, $processStart);
        $this->assertLessThan($sourceInvocation, $connectionObserved);
        $inventoryInvocation = strpos($helper, '$sourceInventoryResult = Invoke-Native');
        $streamMetadata = strpos($helper, "Record-StepMetadata 'source_inventory'", $inventoryInvocation);
        $safeParse = strpos($helper, '$sourceInventory = Assert-RemotePass', $inventoryInvocation);
        $this->assertIsInt($inventoryInvocation);
        $this->assertIsInt($streamMetadata);
        $this->assertIsInt($safeParse);
        $this->assertLessThan($safeParse, $streamMetadata);
    }

    public function test_g5_shared_state_simulation_is_production_free(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('deployment/g5-target-environment/simulate-shared-state.php'),
        ], base_path());
        $process->mustRun();
        $output = $process->getOutput();
        $this->assertStringContainsString('G5_SHARED_STATE_SIMULATION=PASS', $output);
        $this->assertStringContainsString('scenarios=7', $output);
        $this->assertStringContainsString('assertions=36', $output);
        $this->assertStringContainsString('production_connection_attempted=false', $output);
        $this->assertStringContainsString('production_mutation=false', $output);
        $this->assertStringContainsString('secret_values_output=false', $output);
    }

    public function test_g5_shared_state_human_helper_verify_modes_do_not_connect(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The G5 Shared State Human helper runs under Windows PowerShell.');
        }
        $helper = base_path('deployment/g5-target-environment/Invoke-G5SharedState.ps1');
        foreach (['-VerifyPersistenceOnly', '-VerifyOnly'] as $mode) {
            $process = new Process([
                'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $helper,
                '-Attempt', 'Corrective1', $mode,
            ], base_path());
            $process->setTimeout(30);
            $process->run();
            $output = $process->getOutput();
            if (is_dir(base_path(
                'storage/app/release-audit/production-g5-shared-state-corrective-1-'.self::CANDIDATE
            ))) {
                $this->assertSame(1, $process->getExitCode());
                $this->assertStringContainsString('safe_error_code=CORRECTIVE_ATTEMPT_ALREADY_RECORDED', $output);
            } else {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$output);
                $this->assertStringContainsString('shared_state_operation=not_executed', $output);
            }
            $this->assertStringContainsString('production_connection_attempted=false', $output);
            $this->assertStringContainsString('production_mutation=false', $output);
        }
    }

    public function test_g5_shared_state_initial_execution_path_is_retired_without_connection(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The G5 Shared State Human helper runs under Windows PowerShell.');
        }
        $process = new Process([
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            base_path('deployment/g5-target-environment/Invoke-G5SharedState.ps1'),
        ], base_path());
        $process->setTimeout(30);
        $process->run();
        $output = $process->getOutput();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('safe_error_code=SHARED_STATE_ATTEMPT_ALREADY_RECORDED', $output);
        $this->assertStringContainsString('production_connection_attempted=false', $output);
        $this->assertStringContainsString('production_mutation=false', $output);
        $this->assertStringContainsString('retry_available=false', $output);
    }

    public function test_g5_required_source_diagnostic_contract_keeps_required_and_allowlist_contracts_unchanged(): void
    {
        $contractPath = base_path(
            'deployment/g5-target-environment/shared-state-required-diagnostic-contract.json'
        );
        $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
        $base = json_decode((string) file_get_contents(
            base_path('deployment/g5-target-environment/shared-state-contract.json')
        ), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('company-os.ir1.g5-shared-state-required-diagnostic.v1', $contract['contract_id']);
        $this->assertSame(self::CANDIDATE, $contract['candidate']);
        $this->assertSame(
            '403fdcbcc4b9d9cdae9e9d1ec99b07be48ce502ef9601ec7accaa31ec3a08331',
            $contract['evidence_binding']['corrective1_stop_state_sha256'],
        );
        $this->assertSame(172, $contract['evidence_binding']['allowlist_count']);
        $this->assertSame(
            'd408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec',
            $contract['evidence_binding']['allowlist_sha256'],
        );
        $this->assertSame(
            $base['candidate_environment_contract']['required_source_keys'],
            array_column($contract['required_items'], 'key'),
        );
        $this->assertSame(
            ['rk01', 'rk02', 'rk03', 'rk04', 'rk05', 'rk06', 'rk07', 'rk08', 'rk09', 'rk10', 'rk11', 'rk12'],
            array_column($contract['required_items'], 'id'),
        );
        $this->assertSame(
            'feature_required_not_core_boot_required',
            $contract['required_items'][11]['new_target_requirement'],
        );
        $this->assertSame(
            'unsafe_for_continuity',
            $contract['required_items'][0]['generated'],
        );
        $this->assertTrue($contract['key_name_safety']['stable_diagnostic_ids_only']);
        $this->assertFalse($contract['key_name_safety']['execution_evidence_key_names_allowed']);
        $this->assertFalse($contract['production_mutation_authorized']);
        $this->assertFalse($contract['shared_state_creation_authorized']);
        $this->assertFalse($contract['required_contract_change_authorized']);
        $this->assertSame(
            hash_file('sha256', base_path('deployment/g5-target-environment/diagnose-required-source.php')),
            $contract['implementation_binding']['diagnostic_sha256'],
        );
        $this->assertSame(
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            $contract['PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION'],
        );
    }

    public function test_g5_required_source_diagnostic_reports_only_stable_ids_and_value_states(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g5-required-diagnostic-'.bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        $secret = 'required-diagnostic-secret-never-output';
        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        file_put_contents($envPath, implode("\n", [
            'DB_CONNECTION=',
            'DB_HOST=null',
            'DB_PORT=3306',
            'DB_DATABASE=fixture',
            'DB_USERNAME=fixture',
            'DB_PASSWORD='.$secret,
            'MAIL_MAILER=smtp',
            'MAIL_FROM_ADDRESS=fixture@example.com',
            'MAIL_FROM_NAME=Fixture',
            'ACCOUNT_MAIL_MAILER=smtp',
            'OPENAI_API_KEY='.$secret,
            '',
        ]));

        try {
            $process = new Process([
                PHP_BINARY,
                base_path('deployment/g5-target-environment/diagnose-required-source.php'),
                'IR1-G5-SHARED-REQUIRED-DIAGNOSTIC-FIXTURE',
                $envPath,
            ], base_path());
            $process->mustRun();
            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertStringContainsString('G5_SHARED_REQUIRED_DIAGNOSTIC=PASS', $output);
            $this->assertStringContainsString('rk01_state=missing', $output);
            $this->assertStringContainsString('rk02_state=empty', $output);
            $this->assertStringContainsString('rk03_state=null_equivalent', $output);
            $this->assertStringContainsString('rk04_state=present', $output);
            $this->assertStringContainsString('missing_count=1', $output);
            $this->assertStringContainsString('empty_count=1', $output);
            $this->assertStringContainsString('null_equivalent_count=1', $output);
            $this->assertStringContainsString('present_count=9', $output);
            $this->assertStringContainsString('storage_inventory=not_attempted', $output);
            $this->assertStringContainsString('new_target_connection=not_attempted', $output);
            $this->assertStringContainsString('secret_output=false', $output);
            $this->assertStringContainsString('key_names_output=false', $output);
            $this->assertStringContainsString('raw_env_output=false', $output);
            $this->assertStringNotContainsString($secret, $output);
            foreach (array_column(json_decode((string) file_get_contents(
                base_path('deployment/g5-target-environment/shared-state-required-diagnostic-contract.json')
            ), true, 512, JSON_THROW_ON_ERROR)['required_items'], 'key') as $key) {
                $this->assertStringNotContainsString($key, $output);
            }
        } finally {
            @unlink($envPath);
            @rmdir($root);
        }
    }

    public function test_g5_required_source_diagnostic_is_read_only_and_does_not_inventory_storage(): void
    {
        $diagnostic = (string) file_get_contents(
            base_path('deployment/g5-target-environment/diagnose-required-source.php')
        );

        foreach ([
            "PRODUCTION_SOURCE_ENV = '/home/xs257823/rise-gate.com/rise-gate-os/.env'",
            "PRODUCTION_SOURCE_HOME = '/home/xs257823'",
            'PRODUCTION_SOURCE_UID = 20222',
            'PRODUCTION_SOURCE_GID = 1000',
            'storage_inventory=not_attempted',
            'new_target_connection=not_attempted',
            'key_names_output=false',
            'source_env_hash_output=false',
            'production_change_scope=none_read_only_source',
        ] as $required) {
            $this->assertStringContainsString($required, $diagnostic);
        }
        foreach ([
            'file_put_contents', 'unlink(', 'rename(', 'mkdir(', 'rmdir(', 'chmod(', 'chown(',
            'shell_exec', 'exec(', 'system(', 'passthru(', 'proc_open(', 'curl_', 'mysqli_', 'PDO(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $diagnostic);
        }
    }

    public function test_g5_required_source_diagnostic_helper_is_one_shot_source_only_and_secret_safe(): void
    {
        $helper = (string) file_get_contents(
            base_path('deployment/g5-target-environment/Invoke-G5SharedStateRequiredDiagnostic.ps1')
        );
        foreach ([
            'g5-shared-state-required-diagnostic-v1',
            'REQUIRED_DIAGNOSTIC_ATTEMPT_ALREADY_RECORDED',
            'production-g5-shared-state-required-diagnostic-$Candidate',
            'Assert-CorrectiveStopEvidence',
            '403fdcbcc4b9d9cdae9e9d1ec99b07be48ce502ef9601ec7accaa31ec3a08331',
            "'php','--',\$Confirmation,\$SourceEnv",
            'remote_process_count=1',
            'target_connection_attempted=false',
            'production_mutation=false',
            'shared_state_created=false',
            'raw_output_stored=false',
            'secret_values_output=false',
            'key_names_output=false',
            'source_env_hash_output=false',
            'required_contract_disposition=unchanged_pending_human_reconciliation',
            'PENDING_G5_PUBLIC_ENTRY_GATE',
            '[IO.File]::Replace($temporaryPath, $Path, $backupPath)',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
        foreach ([
            'sv17169.xserver.jp', 'xs377816', 'scp.exe', 'Invoke-WebRequest', 'curl.exe',
            'mysql.exe', 'php artisan', 'shared/.env', 'shared/storage',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $helper);
        }
        $processStart = strpos($helper, 'if (-not $process.Start())');
        $connectionObserved = strpos($helper, '$script:ConnectionAttempted = $true');
        $remoteInvocation = strpos($helper, '$result = Invoke-Native $ssh.Source');
        $metadataSave = strpos($helper, '$State.step_streams.source_required_diagnostic', $remoteInvocation);
        $safeParse = strpos($helper, '$diagnostic = Assert-DiagnosticPass $result', $remoteInvocation);
        $this->assertIsInt($processStart);
        $this->assertIsInt($connectionObserved);
        $this->assertIsInt($remoteInvocation);
        $this->assertIsInt($metadataSave);
        $this->assertIsInt($safeParse);
        $this->assertLessThan($connectionObserved, $processStart);
        $this->assertLessThan($remoteInvocation, $connectionObserved);
        $this->assertLessThan($safeParse, $metadataSave);
    }

    public function test_g5_required_source_diagnostic_verify_only_is_production_free(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The G5 diagnostic Human helper runs under Windows PowerShell.');
        }
        $evidenceRoot = base_path(
            'storage/app/release-audit/production-g5-shared-state-required-diagnostic-'.self::CANDIDATE
        );
        $this->assertDirectoryDoesNotExist($evidenceRoot);

        $process = new Process([
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            base_path('deployment/g5-target-environment/Invoke-G5SharedStateRequiredDiagnostic.ps1'),
            '-VerifyOnly',
        ], base_path());
        $process->setTimeout(30);
        $process->mustRun();
        $output = $process->getOutput();

        $this->assertStringContainsString('G5_SHARED_REQUIRED_DIAGNOSTIC_VERIFY_ONLY=PASS', $output);
        $this->assertStringContainsString('production_connection_attempted=false', $output);
        $this->assertStringContainsString('target_connection_attempted=false', $output);
        $this->assertStringContainsString('production_mutation=false', $output);
        $this->assertStringContainsString('diagnostic_operation=not_executed', $output);
        $this->assertStringContainsString('key_names_output=false', $output);
        $this->assertDirectoryDoesNotExist($evidenceRoot);
    }
}
