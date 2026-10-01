<?php

namespace Tests\Feature;

use App\Services\Release\R0AuditBundleVerifier;
use Tests\TestCase;

class R0HumanStepHelperTest extends TestCase
{
    public function test_helper_is_fail_closed_single_command_orchestration(): void
    {
        $helper = base_path('deployment/r0-audit/Invoke-R0HumanStep.ps1');
        $this->assertFileExists($helper);

        $source = file_get_contents($helper);
        $this->assertIsString($source);
        $this->assertStringContainsString('[ValidateSet(2, 3, 4, 5, 6)]', $source);
        $this->assertStringContainsString(R0AuditBundleVerifier::EXACT_CANDIDATE_COMMIT, $source);
        $this->assertStringContainsString('5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0', $source);
        $this->assertStringContainsString('a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d', $source);

        foreach ([
            'BatchMode=yes',
            'StrictHostKeyChecking=yes',
            'NumberOfPasswordPrompts=0',
            'ConnectionAttempts=1',
            'ClearAllForwardings=yes',
            'STEP_RETRY_FORBIDDEN',
            'PREVIOUS_STEP_NOT_PASS',
            'corrective-2',
            'Write-SanitizedFailureReceipt',
            'failure_stage',
            'production_connection_attempted',
            'VerifyLocalPreconditionsOnly',
            'InspectStep2RemoteStateOnly',
            'InspectStep2RemoteContentsOnly',
            'ReconcileStep2PreparedStateOnly',
            'LOCAL_SSH_CONFIG_PARSE',
            'LOCAL_HOST_KEY_LOOKUP',
            'LOCAL_NATIVE_PROCESS_CAPTURE_FAILURE',
            'previousErrorActionPreference',
            'native_stderr_capture_verified=true',
            'STEP_2_STATE_INSPECTION_RETRY_FORBIDDEN',
            'production_change_scope=none_read_only_state_inspection',
            'STEP_2_CONTENT_INSPECTION_RETRY_FORBIDDEN',
            'production_change_scope=none_read_only_content_inspection',
            'EVIDENCE_RECONCILIATION',
            'ADOPTED_EXISTING_EMPTY_DIRECTORIES',
            'STEP_2_RECONCILIATION_ALREADY_APPLIED',
            'attempt_performed=false',
            'production_mutation=false',
            'step_2_retry=false',
            'step_3_eligible=true',
            'step_3_executed=false',
            'step_3_eligibility_contract_verified=true',
            'Assert-Step3AdoptedCandidateContract',
            'STEP_3_ADOPTED_CANDIDATE_CONTRACT_MISMATCH',
            '.step3-placement',
            'STEP_3_PLACEMENT_PREPARE_FAILED',
            'STEP_3_PLACEMENT_FINALIZE_FAILED',
            'bundle_sha256_verified=true',
            'overwrite_performed=false',
            'production_change_scope=single_audit_archive_placement_only',
            'step_3_placement_guard_verified=true',
            'c979f7d3b90fc224ccab34644c35e5f1319154da291dcdd506e39c8101352330',
            'Assert-Step4PlacedBundleContract',
            'STEP_4_PLACED_BUNDLE_CONTRACT_MISMATCH',
            'a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7',
            'bundle_manifest_sha256_verified=true',
            'bundle_symlink_count=0',
            'step_4_placed_bundle_contract_verified=true',
            'step_4_extraction_guard_verified=true',
            'raw_exception_stored',
            'secret_output=false',
            'next_action=RETURN_TO_HUMAN_CHATGPT',
        ] as $required) {
            $this->assertStringContainsString($required, $source);
        }

        $this->assertStringContainsString('mkdir "$AUDIT_ROOT"', $source);
        $this->assertStringContainsString('mkdir "$AUDIT_DIR"', $source);
        $this->assertStringContainsString('test ! -e "$AUDIT_ROOT"', $source);
        $this->assertStringContainsString('production_change_scope=isolated_audit_directories_only', $source);
        $this->assertStringContainsString('Get-Step2StateInspectionScript', $source);
        $this->assertStringContainsString('SELF_TEST_STEP_2_INSPECTION_MUTATION_PRESENT', $source);
        $this->assertStringContainsString('Get-Step2ContentInspectionScript', $source);
        $this->assertStringContainsString('SELF_TEST_STEP_2_CONTENT_INSPECTION_MUTATION_PRESENT', $source);
        $this->assertStringNotContainsString('Write-Output $result.Stdout', $source);
        $this->assertStringNotContainsString('Write-Output $result.Stderr', $source);
        $this->assertStringNotContainsString('Remove-Item -Recurse', $source);
        $this->assertStringNotContainsString('rm -rf', $source);
        $this->assertStringNotContainsString('migrate --force', $source);
        $this->assertStringNotContainsString('git push', $source);
        $this->assertStringNotContainsString('ln -f', $source);
        $this->assertStringNotContainsString('tar --overwrite', $source);
    }
}
