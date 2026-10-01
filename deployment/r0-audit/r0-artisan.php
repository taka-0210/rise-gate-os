<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Symfony\Component\Console\Input\ArgvInput;

const R0_OUTPUT_SCHEMA_VERSION = 2;

/** @return never */
function r0BootstrapFailure(string $safeErrorCode, string $failureStage): void
{
    fwrite(STDOUT, json_encode([
        'output_schema_version' => R0_OUTPUT_SCHEMA_VERSION,
        'status' => 'INCONCLUSIVE',
        'audit_mode' => 'read-only',
        'evidence_completeness' => 'incomplete',
        'failure' => [
            'safe_error_code' => $safeErrorCode,
            'failure_stage' => $failureStage,
        ],
        'evidence' => null,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}

$bundleRoot = realpath(__DIR__.'/../..');
$environmentFile = getenv('IR1_R0_ENV_FILE');

if ($bundleRoot === false || ! is_string($environmentFile) || trim($environmentFile) === '') {
    r0BootstrapFailure('R0_ENV_FILE_REQUIRED', 'bootstrap_preflight');
}

$environmentFile = realpath($environmentFile);
if ($environmentFile === false || ! is_file($environmentFile) || ! is_readable($environmentFile)) {
    r0BootstrapFailure('R0_ENV_FILE_UNAVAILABLE', 'bootstrap_preflight');
}

try {
    require $bundleRoot.'/vendor/autoload.php';

    // The Production .env is read into this process only. It is never copied,
    // linked, displayed or written into the audit bundle.
    Dotenv::createImmutable(dirname($environmentFile), basename($environmentFile))->load();

    // Keep framework diagnostics on stderr and prevent a file log mutation.
    putenv('LOG_CHANNEL=stderr');
    $_ENV['LOG_CHANNEL'] = 'stderr';
    $_SERVER['LOG_CHANNEL'] = 'stderr';

    $app = require $bundleRoot.'/bootstrap/app.php';
    $status = $app->handleCommand(new ArgvInput);
    exit($status);
} catch (Throwable) {
    r0BootstrapFailure('R0_BOOTSTRAP_FAILED', 'laravel_bootstrap');
}
