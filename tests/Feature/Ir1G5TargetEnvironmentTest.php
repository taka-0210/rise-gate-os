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
            $process->mustRun();
            $output = $process->getOutput();
            $this->assertStringContainsString('production_connection_attempted=false', $output);
            $this->assertStringContainsString('production_mutation=false', $output);
            $this->assertStringContainsString('target_skeleton_build=not_executed', $output);
        }
    }
}
