<?php

namespace Tests\Unit;

use App\Services\AiCommon\Realtime\ProviderEvidenceProcessRunner;
use Tests\TestCase;

class ProviderEvidenceProcessRunnerTest extends TestCase
{
    private ProviderEvidenceProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ProviderEvidenceProcessRunner;
    }

    public function test_valid_json_exit_zero_is_complete_pass(): void
    {
        $result = $this->runScenario('valid_exit_zero');

        $this->assertSame('PASS', data_get($result, 'evidence.final_classification'));
        $this->assertSame('valid', data_get($result, 'evidence.json_parse_state'));
        $this->assertSame('complete', data_get($result, 'evidence.evidence_completeness'));
    }

    public function test_valid_json_non_zero_exit_is_runtime_failure_with_payload(): void
    {
        $result = $this->runScenario('valid_non_zero');

        $this->assertSame('RUNTIME_FAILURE', data_get($result, 'evidence.final_classification'));
        $this->assertSame(23, data_get($result, 'evidence.safe_exit_code'));
        $this->assertSame('valid', data_get($result, 'evidence.json_parse_state'));
        $this->assertSame('partial', data_get($result, 'evidence.evidence_completeness'));
        $this->assertSame('unknown', data_get($result, 'evidence.safe_reason'));
        $this->assertNotNull($result['payload']);
    }

    public function test_explicit_provider_failure_is_not_misclassified_as_runtime(): void
    {
        $result = $this->runScenario('provider_failure');

        $this->assertSame('PROVIDER_FAILURE', data_get($result, 'evidence.final_classification'));
        $this->assertTrue(data_get($result, 'evidence.provider_connection_state'));
        $this->assertFalse(data_get($result, 'evidence.provider_acceptance_state'));
        $this->assertSame(0, data_get($result, 'evidence.audio_samples'));
        $this->assertSame('synthetic_provider_rejection', data_get($result, 'evidence.safe_reason'));
        $this->assertSame('provider', data_get($result, 'evidence.error_layer_classification'));
        $this->assertSame('complete', data_get($result, 'evidence.evidence_completeness'));
    }

    public function test_sdk_open_failure_keeps_complete_sanitized_diagnostics(): void
    {
        $result = $this->runScenario('sdk_open_failure');

        $this->assertSame('RUNTIME_FAILURE', data_get($result, 'evidence.final_classification'));
        $this->assertSame('synthetic_sdk_open_failure', data_get($result, 'evidence.safe_reason'));
        $this->assertSame('session', data_get($result, 'evidence.error_layer_classification'));
        $this->assertSame('complete', data_get($result, 'evidence.evidence_completeness'));
    }

    public function test_connection_abort_is_classified_without_inference(): void
    {
        $result = $this->runScenario('connection_abort');

        $this->assertSame('RUNTIME_FAILURE', data_get($result, 'evidence.final_classification'));
        $this->assertSame('synthetic_connection_abort', data_get($result, 'evidence.safe_reason'));
        $this->assertSame(1006, data_get($result, 'evidence.close_state'));
        $this->assertFalse(data_get($result, 'evidence.unknown_inferred'));
    }

    public function test_lifecycle_mismatch_is_explicit_and_complete(): void
    {
        $result = $this->runScenario('lifecycle_mismatch');

        $this->assertSame('RUNTIME_FAILURE', data_get($result, 'evidence.final_classification'));
        $this->assertSame('synthetic_redundant_connect_detected', data_get($result, 'evidence.safe_reason'));
        $this->assertSame('runtime', data_get($result, 'evidence.error_layer_classification'));
        $this->assertSame('complete', data_get($result, 'evidence.evidence_completeness'));
    }

    public function test_child_runtime_failure_retains_reason_and_layer(): void
    {
        $result = $this->runScenario('child_runtime_failure');

        $this->assertSame(70, data_get($result, 'evidence.safe_exit_code'));
        $this->assertSame('synthetic_child_runtime_failure', data_get($result, 'evidence.safe_reason'));
        $this->assertSame('runtime', data_get($result, 'evidence.error_layer_classification'));
        $this->assertSame('complete', data_get($result, 'evidence.evidence_completeness'));
    }

    public function test_child_reason_is_sanitized_again_at_parent_boundary(): void
    {
        $result = $this->runScenario('secret_reason');
        $reason = (string) data_get($result, 'evidence.safe_reason');

        $this->assertStringContainsString('[REDACTED]', $reason);
        $this->assertStringNotContainsString('secret-value', $reason);
        $this->assertStringNotContainsString('second-secret', $reason);
        $this->assertSame('runtime', data_get($result, 'evidence.error_layer_classification'));
    }

    public function test_stdout_noise_is_separated_from_the_run_specific_frame(): void
    {
        $result = $this->runScenario('noise');

        $this->assertSame('PASS', data_get($result, 'evidence.final_classification'));
        $this->assertGreaterThan(0, data_get($result, 'evidence.capture.stdout.noise_before_bytes'));
        $this->assertGreaterThan(0, data_get($result, 'evidence.capture.stdout.noise_after_bytes'));
    }

    public function test_multiline_json_frame_is_deterministic(): void
    {
        $result = $this->runScenario('multiline');

        $this->assertSame('valid', data_get($result, 'evidence.json_parse_state'));
        $this->assertSame('PASS', data_get($result, 'evidence.final_classification'));
    }

    public function test_stderr_is_hashed_without_breaking_a_valid_frame(): void
    {
        $result = $this->runScenario('stderr');

        $this->assertSame('captured', data_get($result, 'evidence.stderr_capture_state'));
        $this->assertSame(64, strlen((string) data_get($result, 'evidence.capture.stderr.sha256')));
        $this->assertSame('PASS', data_get($result, 'evidence.final_classification'));
        $this->assertArrayNotHasKey('raw', data_get($result, 'evidence.capture.stderr'));
    }

    public function test_empty_stdout_is_evidence_capture_failure_with_unknown_provider_state(): void
    {
        $result = $this->runScenario('empty');

        $this->assertSame('empty_stdout', data_get($result, 'evidence.json_parse_state'));
        $this->assertSame('EVIDENCE_CAPTURE_FAILURE', data_get($result, 'evidence.final_classification'));
        $this->assertSame('unknown', data_get($result, 'evidence.provider_acceptance_state'));
    }

    public function test_malformed_json_is_preserved_as_parse_state_not_an_exception(): void
    {
        $result = $this->runScenario('malformed');

        $this->assertSame('json_malformed', data_get($result, 'evidence.json_parse_state'));
        $this->assertSame('EVIDENCE_CAPTURE_FAILURE', data_get($result, 'evidence.final_classification'));
    }

    public function test_truncated_frame_is_detected_without_guessing(): void
    {
        $result = $this->runScenario('truncated');

        $this->assertSame('frame_truncated', data_get($result, 'evidence.json_parse_state'));
        $this->assertSame('unknown', data_get($result, 'evidence.audio_samples'));
        $this->assertFalse(data_get($result, 'evidence.unknown_inferred'));
    }

    public function test_child_timeout_is_stopped_and_classified(): void
    {
        $result = $this->runScenario('timeout', timeout: 0.15);

        $this->assertSame('timeout', data_get($result, 'evidence.process_exit_state'));
        $this->assertSame('CHILD_TIMEOUT', data_get($result, 'evidence.final_classification'));
        $this->assertTrue(data_get($result, 'evidence.child_started'));
    }

    public function test_child_kill_is_distinct_from_timeout(): void
    {
        $result = $this->runScenario('kill', timeout: 2.0, killAfter: 0.10);

        $this->assertSame('killed', data_get($result, 'evidence.process_exit_state'));
        $this->assertSame('CHILD_KILLED', data_get($result, 'evidence.final_classification'));
    }

    public function test_abnormal_exit_is_runtime_failure_without_missing_evidence_exception(): void
    {
        $result = $this->runScenario('abnormal');

        $this->assertSame('RUNTIME_FAILURE', data_get($result, 'evidence.final_classification'));
        $this->assertSame(137, data_get($result, 'evidence.safe_exit_code'));
        $this->assertSame('empty_stdout', data_get($result, 'evidence.json_parse_state'));
    }

    private function runScenario(string $scenario, float $timeout = 2.0, ?float $killAfter = null): array
    {
        $result = $this->runner->run(
            [PHP_BINARY, base_path('tests/Fixtures/p1_i_evidence_child.php'), $scenario],
            base_path(),
            timeoutSeconds: $timeout,
            killAfterSeconds: $killAfter,
        );
        foreach ([
            'attempt_started', 'child_started', 'process_exit_state', 'safe_exit_code',
            'stdout_capture_state', 'stderr_capture_state', 'json_parse_state',
            'provider_connection_state', 'provider_acceptance_state', 'audio_send_state',
            'audio_samples', 'event_count', 'close_state', 'evidence_completeness', 'final_classification',
            'safe_reason', 'error_layer_classification',
        ] as $required) {
            $this->assertArrayHasKey($required, $result['evidence'], $scenario.' missing '.$required);
        }
        $this->assertFalse($result['evidence']['unknown_inferred']);
        $this->assertFalse($result['evidence']['raw_stdout_persisted']);
        $this->assertFalse($result['evidence']['raw_stderr_persisted']);
        $this->assertFalse($result['evidence']['raw_provider_payload_persisted']);

        return $result;
    }
}
