<?php

namespace App\Services\AiCommon\Realtime;

use Symfony\Component\Process\Process;
use Throwable;

final class ProviderEvidenceProcessRunner
{
    public const IPC_VERSION = 'sentinel-json-v1';

    public function run(
        array $command,
        string $workingDirectory,
        array $environment = [],
        float $timeoutSeconds = 70.0,
        ?float $killAfterSeconds = null,
    ): array {
        $frameId = bin2hex(random_bytes(16));
        $process = new Process($command, $workingDirectory, [
            'COMPANY_OS_EVIDENCE_FRAME_ID' => $frameId,
        ] + $environment);
        $childStarted = false;
        $timedOut = false;
        $killed = false;
        $startFailure = null;
        $startedAt = microtime(true);

        try {
            $process->start();
            $childStarted = true;
            while ($process->isRunning()) {
                $elapsed = microtime(true) - $startedAt;
                if ($killAfterSeconds !== null && $elapsed >= $killAfterSeconds) {
                    $killed = true;
                    $process->stop(0.05, 9);
                    break;
                }
                if ($elapsed >= $timeoutSeconds) {
                    $timedOut = true;
                    $process->stop(0.05, 9);
                    break;
                }
                usleep(10_000);
            }
        } catch (Throwable $exception) {
            $startFailure = $exception::class;
            if ($process->isRunning()) {
                $process->stop(0.05, 9);
            }
        }

        $stdout = $childStarted ? $process->getOutput() : '';
        $stderr = $childStarted ? $process->getErrorOutput() : '';
        $parsed = $this->parseFrame($stdout, $frameId);
        $exitCode = $childStarted ? $process->getExitCode() : null;
        $exitState = $startFailure !== null ? 'start_failure'
            : ($timedOut ? 'timeout' : ($killed ? 'killed' : 'exited'));
        $classification = $this->classify($parsed['payload'], $parsed['state'], $exitCode, $exitState);
        $provider = $this->safeProviderEvidence($parsed['payload']);
        $capture = [
            'stdout' => $this->captureState($stdout) + [
                'noise_before_bytes' => $parsed['noise_before_bytes'],
                'noise_after_bytes' => $parsed['noise_after_bytes'],
            ],
            'stderr' => $this->captureState($stderr),
        ];
        $baseComplete = $parsed['state'] === 'valid'
            && ! in_array('unknown', [
                $provider['connection_state'], $provider['acceptance_state'], $provider['audio_send_state'],
                $provider['audio_samples'], $provider['event_count'], $provider['close_state'],
            ], true);
        $diagnosticsComplete = $classification === 'PASS'
            || ($provider['safe_reason'] !== 'unknown' && $provider['error_layer'] !== 'unknown');
        $complete = $baseComplete && $diagnosticsComplete;

        return [
            'payload' => $parsed['payload'],
            'evidence' => [
                'schema_version' => 1,
                'ipc' => self::IPC_VERSION,
                'attempt_started' => true,
                'child_started' => $childStarted,
                'process_exit_state' => $exitState,
                'safe_exit_code' => $exitCode ?? 'unknown',
                'start_failure_class' => $startFailure ?? 'none',
                'stdout_capture_state' => $capture['stdout']['state'],
                'stderr_capture_state' => $capture['stderr']['state'],
                'capture' => $capture,
                'json_parse_state' => $parsed['state'],
                'provider_connection_state' => $provider['connection_state'],
                'provider_acceptance_state' => $provider['acceptance_state'],
                'audio_send_state' => $provider['audio_send_state'],
                'audio_samples' => $provider['audio_samples'],
                'audio_bytes' => $provider['audio_bytes'],
                'audio_duration_seconds' => $provider['audio_duration_seconds'],
                'event_count' => $provider['event_count'],
                'close_state' => $provider['close_state'],
                'safe_reason' => $provider['safe_reason'],
                'error_layer_classification' => $provider['error_layer'],
                'safe_request_projection' => $provider['request'],
                'evidence_completeness' => $parsed['state'] === 'valid' ? ($complete ? 'complete' : 'partial') : 'missing',
                'final_classification' => $classification,
                'unknown_inferred' => false,
                'raw_stdout_persisted' => false,
                'raw_stderr_persisted' => false,
                'raw_provider_payload_persisted' => false,
            ],
        ];
    }

    private function parseFrame(string $stdout, string $frameId): array
    {
        $begin = "@@COMPANY_OS_EVIDENCE_V1:{$frameId}:BEGIN@@";
        $end = "@@COMPANY_OS_EVIDENCE_V1:{$frameId}:END@@";
        $beginAt = strrpos($stdout, $begin);
        if ($beginAt === false) {
            return $this->parsed(trim($stdout) === '' ? 'empty_stdout' : 'frame_missing');
        }
        $jsonAt = $beginAt + strlen($begin);
        $endAt = strpos($stdout, $end, $jsonAt);
        if ($endAt === false) {
            return $this->parsed('frame_truncated', null, $beginAt, 0);
        }
        $json = trim(substr($stdout, $jsonAt, $endAt - $jsonAt));
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->parsed('json_malformed', null, $beginAt, strlen($stdout) - ($endAt + strlen($end)));
        }
        if (! is_array($payload)) {
            return $this->parsed('schema_invalid', null, $beginAt, strlen($stdout) - ($endAt + strlen($end)));
        }

        return $this->parsed('valid', $payload, $beginAt, strlen($stdout) - ($endAt + strlen($end)));
    }

    private function parsed(string $state, ?array $payload = null, int $before = 0, int $after = 0): array
    {
        return ['state' => $state, 'payload' => $payload, 'noise_before_bytes' => $before, 'noise_after_bytes' => $after];
    }

    private function captureState(string $value): array
    {
        return [
            'state' => $value === '' ? 'empty' : 'captured',
            'bytes' => strlen($value),
            'sha256' => $value === '' ? null : hash('sha256', $value),
            'raw_persisted' => false,
        ];
    }

    private function classify(?array $payload, string $parseState, ?int $exitCode, string $exitState): string
    {
        if ($exitState === 'start_failure') {
            return 'RUNTIME_START_FAILURE';
        }
        if ($exitState === 'timeout') {
            return 'CHILD_TIMEOUT';
        }
        if ($exitState === 'killed') {
            return 'CHILD_KILLED';
        }
        if ($exitCode !== null && $exitCode !== 0 && $parseState !== 'valid') {
            return 'RUNTIME_FAILURE';
        }
        if ($parseState !== 'valid') {
            return 'EVIDENCE_CAPTURE_FAILURE';
        }
        $status = strtoupper((string) ($payload['status'] ?? ''));
        if ($exitCode === 0 && $status === 'PASS') {
            return 'PASS';
        }
        if ($status === 'PROVIDER_FAILURE' || data_get($payload, 'errors.0.classification') === 'provider') {
            return 'PROVIDER_FAILURE';
        }
        if ($exitCode !== 0 || $status === 'FAIL') {
            return 'RUNTIME_FAILURE';
        }

        return 'INCONCLUSIVE_EVIDENCE_FAILURE';
    }

    private function safeProviderEvidence(?array $payload): array
    {
        if ($payload === null) {
            return $this->unknownProviderEvidence();
        }
        $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        $connection = is_array($payload['connection'] ?? null) ? $payload['connection'] : [];
        $request = is_array($payload['request'] ?? null) ? $payload['request'] : [];
        $error = is_array(data_get($payload, 'errors.0')) ? data_get($payload, 'errors.0') : [];
        $eventCount = $this->sumKnownIntegers([$counts['partials'] ?? null, $counts['finals'] ?? null, $counts['metadata'] ?? null]);

        return [
            'connection_state' => $this->known($checks['provider_connection_attempted'] ?? null),
            'acceptance_state' => $this->known($checks['provider_accepted'] ?? null),
            'audio_send_state' => $this->known($checks['audio_send_completed'] ?? ($checks['audio_send_started'] ?? null)),
            'audio_samples' => $this->known($source['samples_sent'] ?? ($source['sample_frames'] ?? null)),
            'audio_bytes' => $this->known($source['bytes_sent'] ?? null),
            'audio_duration_seconds' => $this->known($source['duration_sent_seconds'] ?? null),
            'event_count' => $eventCount,
            'close_state' => $this->known($connection['provider_close_code'] ?? null),
            'safe_reason' => $this->sanitizeReason($payload['safe_reason'] ?? ($error['safe_reason'] ?? null)),
            'error_layer' => $this->known($error['classification'] ?? null),
            'request' => array_intersect_key($request, array_flip([
                'model', 'language', 'encoding', 'sample_rate', 'channels', 'diarize', 'interim_results',
                'mip_opt_out', 'reconnectAttempts',
            ])),
        ];
    }

    private function unknownProviderEvidence(): array
    {
        return [
            'connection_state' => 'unknown', 'acceptance_state' => 'unknown', 'audio_send_state' => 'unknown',
            'audio_samples' => 'unknown', 'audio_bytes' => 'unknown', 'audio_duration_seconds' => 'unknown',
            'event_count' => 'unknown', 'close_state' => 'unknown', 'safe_reason' => 'unknown',
            'error_layer' => 'unknown', 'request' => [],
        ];
    }

    private function sanitizeReason(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'unknown';
        }
        $sanitized = preg_replace(
            ['/(authorization|api[-_ ]?key|token|credential)\s*[:=]\s*\S+/i', '/Bearer\s+\S+/i'],
            ['$1=[REDACTED]', 'Bearer [REDACTED]'],
            $value,
        );

        return mb_substr((string) $sanitized, 0, 240);
    }

    private function known(mixed $value): mixed
    {
        return is_bool($value) || is_int($value) || is_float($value) || is_string($value) && $value !== '' ? $value : 'unknown';
    }

    private function sumKnownIntegers(array $values): int|string
    {
        foreach ($values as $value) {
            if (! is_int($value)) {
                return 'unknown';
            }
        }

        return array_sum($values);
    }
}
