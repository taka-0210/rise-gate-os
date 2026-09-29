<?php

$scenario = $argv[1] ?? 'empty';
$frameId = (string) getenv('COMPANY_OS_EVIDENCE_FRAME_ID');
$begin = "@@COMPANY_OS_EVIDENCE_V1:{$frameId}:BEGIN@@";
$end = "@@COMPANY_OS_EVIDENCE_V1:{$frameId}:END@@";
$payload = [
    'status' => 'PASS',
    'checks' => [
        'provider_connection_attempted' => true,
        'provider_accepted' => true,
        'audio_send_started' => true,
        'audio_send_completed' => true,
    ],
    'request' => ['model' => 'synthetic', 'mip_opt_out' => 'true', 'reconnectAttempts' => 0],
    'source' => ['samples_sent' => 1600, 'bytes_sent' => 3200, 'duration_sent_seconds' => 0.1],
    'counts' => ['partials' => 1, 'finals' => 1, 'metadata' => 1],
    'connection' => ['provider_close_code' => 1000],
];

$writeFrame = static function (array $value, bool $pretty = false) use ($begin, $end): void {
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0);
    echo $begin, PHP_EOL, json_encode($value, $flags), PHP_EOL, $end, PHP_EOL;
};

match ($scenario) {
    'valid_exit_zero' => $writeFrame($payload),
    'valid_non_zero' => (function () use ($writeFrame, $payload): never {
        $payload['status'] = 'INCONCLUSIVE_EVIDENCE_FAILURE';
        $writeFrame($payload);
        exit(23);
    })(),
    'provider_failure' => (function () use ($writeFrame, $payload): never {
        $payload['status'] = 'PROVIDER_FAILURE';
        $payload['checks']['provider_accepted'] = false;
        $payload['checks']['audio_send_started'] = false;
        $payload['checks']['audio_send_completed'] = false;
        $payload['source'] = ['samples_sent' => 0, 'bytes_sent' => 0, 'duration_sent_seconds' => 0.0];
        $payload['counts'] = ['partials' => 0, 'finals' => 0, 'metadata' => 0];
        $payload['connection'] = ['provider_close_code' => 1008];
        $payload['errors'] = [['classification' => 'provider', 'safe_reason' => 'synthetic_provider_rejection']];
        $writeFrame($payload);
        exit(1);
    })(),
    'noise' => (function () use ($writeFrame, $payload): void {
        echo "unrelated-prefix\n";
        $writeFrame($payload);
        echo "unrelated-suffix\n";
    })(),
    'multiline' => $writeFrame($payload, true),
    'stderr' => (function () use ($writeFrame, $payload): void {
        fwrite(STDERR, "synthetic stderr without secret\n");
        $writeFrame($payload);
    })(),
    'empty' => null,
    'malformed' => print $begin."\n{not-json}\n".$end."\n",
    'truncated' => print $begin."\n{\"status\":\"PASS\"",
    'timeout', 'kill' => sleep(3),
    'abnormal' => exit(137),
    default => exit(64),
};
