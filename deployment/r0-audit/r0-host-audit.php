<?php

declare(strict_types=1);

const R0_HOST_OUTPUT_SCHEMA_VERSION = 2;
const R0_HOST_EXACT_CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

/** @return never */
function failSafe(string $safeErrorCode, string $failureStage): void
{
    fwrite(STDOUT, json_encode([
        'output_schema_version' => R0_HOST_OUTPUT_SCHEMA_VERSION,
        'status' => 'INCONCLUSIVE',
        'audit_mode' => 'read-only',
        'evidence_completeness' => 'incomplete',
        'failure' => ['safe_error_code' => $safeErrorCode, 'failure_stage' => $failureStage],
        'evidence' => null,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}

/** @return array<string, mixed> */
function pathEvidence(string $path): array
{
    $exists = file_exists($path) || is_link($path);
    $target = is_link($path) ? readlink($path) : false;
    $resolved = realpath($path);
    $permissions = $exists ? fileperms($path) : false;

    return [
        'exists' => $exists,
        'is_symlink' => is_link($path),
        'path_sha256' => hash('sha256', str_replace('\\', '/', $path)),
        'target_sha256' => is_string($target) ? hash('sha256', str_replace('\\', '/', $target)) : null,
        'target_exists' => $resolved !== false,
        'readable' => $exists ? is_readable($path) : false,
        'writable' => $exists ? is_writable($path) : false,
        'permissions_octal' => is_int($permissions) ? substr(sprintf('%o', $permissions), -4) : null,
        'owner_uid' => $exists ? fileowner($path) : null,
        'group_gid' => $exists ? filegroup($path) : null,
        'disk_total_bytes' => $exists ? disk_total_space($path) : null,
        'disk_free_bytes' => $exists ? disk_free_space($path) : null,
    ];
}

/** @return array<string, mixed> */
function releaseMarker(string $currentPath): array
{
    $target = realpath($currentPath);
    if ($target === false) {
        return ['status' => 'UNKNOWN', 'reason' => 'current_target_unavailable'];
    }

    $marker = $target.DIRECTORY_SEPARATOR.'.release-manifest.json';
    if (! is_file($marker) || ! is_readable($marker)) {
        return ['status' => 'UNKNOWN', 'reason' => 'release_marker_unavailable'];
    }

    try {
        $data = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return ['status' => 'UNKNOWN', 'reason' => 'release_marker_invalid'];
    }

    if (! is_array($data)) {
        return ['status' => 'UNKNOWN', 'reason' => 'release_marker_invalid'];
    }

    $rcSha = (string) ($data['rc_sha'] ?? '');
    $artifactSha = (string) ($data['artifact_sha256'] ?? '');
    $releaseCase = (string) ($data['release_case'] ?? '');

    return [
        'status' => preg_match('/\A[a-f0-9]{40}\z/', $rcSha)
            && preg_match('/\A[a-f0-9]{64}\z/', $artifactSha)
            && preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $releaseCase) ? 'SUPPORTED' : 'UNKNOWN',
        'rc_sha' => preg_match('/\A[a-f0-9]{40}\z/', $rcSha) ? $rcSha : null,
        'artifact_sha256' => preg_match('/\A[a-f0-9]{64}\z/', $artifactSha) ? $artifactSha : null,
        'release_case' => preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $releaseCase) ? $releaseCase : null,
    ];
}

/** @return array<string, mixed> */
function processEvidence(): array
{
    if (! is_dir('/proc') || ! is_readable('/proc')) {
        return ['status' => 'UNKNOWN', 'queue_worker_count' => null, 'scheduler_process_count' => null];
    }

    $queueWorkers = 0;
    $schedulers = 0;
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
        $command = @file_get_contents($path);
        if (! is_string($command) || $command === '') {
            continue;
        }
        $command = str_replace("\0", ' ', $command);
        $queueWorkers += str_contains($command, 'artisan queue:work') ? 1 : 0;
        $schedulers += str_contains($command, 'artisan schedule:work') ? 1 : 0;
    }

    return ['status' => 'SUPPORTED', 'queue_worker_count' => $queueWorkers, 'scheduler_process_count' => $schedulers];
}

/** @return array<string, mixed> */
function cronEvidence(): array
{
    if (! function_exists('proc_open')) {
        return ['status' => 'UNKNOWN', 'scheduler_entry_count' => null];
    }

    $pipes = [];
    $process = @proc_open(['crontab', '-l'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        return ['status' => 'UNKNOWN', 'scheduler_entry_count' => null];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    unset($stderr);

    if ($exit !== 0 || ! is_string($stdout)) {
        return ['status' => 'UNKNOWN', 'scheduler_entry_count' => null];
    }

    $count = 0;
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && ! str_starts_with($line, '#') && preg_match('/artisan\s+schedule:(?:run|work)/', $line)) {
            $count++;
        }
    }

    return ['status' => 'SUPPORTED', 'scheduler_entry_count' => $count];
}

/** @return array<string, mixed> */
function backupEvidence(?string $path): array
{
    if ($path === null || $path === '') {
        return ['status' => 'UNKNOWN', 'reason' => 'backup_root_not_supplied', 'restore_readiness' => 'UNKNOWN'];
    }
    if (! is_dir($path) || ! is_readable($path)) {
        return ['status' => 'UNKNOWN', 'reason' => 'backup_root_unavailable', 'restore_readiness' => 'UNKNOWN'];
    }

    $count = 0;
    $bytes = 0;
    $latest = null;
    $truncated = false;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->isLink()) {
            continue;
        }
        $count++;
        $bytes += $file->getSize();
        $latest = max($latest ?? 0, $file->getMTime());
        if ($count >= 10000) {
            $truncated = true;
            break;
        }
    }

    return [
        'status' => 'SUPPORTED',
        'file_count' => $count,
        'total_bytes_scanned' => $bytes,
        'newest_mtime_jst' => is_int($latest) ? (new DateTimeImmutable('@'.$latest))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s T') : null,
        'scan_truncated' => $truncated,
        'restore_readiness' => 'UNKNOWN',
    ];
}

$options = getopt('', ['bundle-manifest:', 'current-link:', 'previous-link:', 'shared-root:', 'backup-root::']);
foreach (['bundle-manifest', 'current-link', 'previous-link', 'shared-root'] as $required) {
    if (! isset($options[$required]) || ! is_string($options[$required]) || trim($options[$required]) === '') {
        failSafe('R0_HOST_REQUIRED_OPTION_MISSING', 'host_preflight');
    }
}

try {
    $manifestPath = realpath($options['bundle-manifest']);
    $bundleRoot = realpath(__DIR__.'/../..');
    if ($manifestPath === false || $bundleRoot === false || ! is_file($manifestPath)) {
        failSafe('R0_BUNDLE_MANIFEST_UNAVAILABLE', 'host_preflight');
    }
    $normalizedRoot = rtrim(str_replace('\\', '/', $bundleRoot), '/').'/';
    if (! str_starts_with(str_replace('\\', '/', $manifestPath), $normalizedRoot)) {
        failSafe('R0_BUNDLE_MANIFEST_OUTSIDE_ROOT', 'host_preflight');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($manifest)
        || ($manifest['source_commit'] ?? null) !== R0_HOST_EXACT_CANDIDATE
        || ($manifest['output_schema_version'] ?? null) !== R0_HOST_OUTPUT_SCHEMA_VERSION) {
        failSafe('R0_BUNDLE_IDENTITY_MISMATCH', 'host_preflight');
    }
    $selfRelative = 'deployment/r0-audit/r0-host-audit.php';
    $expectedSelfHash = $manifest['critical_files'][$selfRelative] ?? null;
    $actualSelfHash = hash_file('sha256', __FILE__);
    if (! is_string($expectedSelfHash) || $actualSelfHash === false || ! hash_equals($expectedSelfHash, $actualSelfHash)) {
        failSafe('R0_HOST_SCRIPT_HASH_MISMATCH', 'host_preflight');
    }

    $current = (string) $options['current-link'];
    $extensions = get_loaded_extensions();
    sort($extensions);

    fwrite(STDOUT, json_encode([
        'output_schema_version' => R0_HOST_OUTPUT_SCHEMA_VERSION,
        'status' => 'PASS',
        'audit_mode' => 'read-only',
        'evidence_completeness' => 'complete_for_supported_capabilities',
        'failure' => null,
        'evidence' => [
            'bundle' => [
                'source_commit' => R0_HOST_EXACT_CANDIDATE,
                'bundle_id' => (string) ($manifest['bundle_id'] ?? ''),
                'bundle_manifest_sha256' => hash_file('sha256', $manifestPath),
            ],
            'php_cli' => ['version' => PHP_VERSION, 'extensions' => $extensions],
            'filesystem' => [
                'current' => pathEvidence($current),
                'current_previous' => pathEvidence((string) $options['previous-link']),
                'shared' => pathEvidence((string) $options['shared-root']),
                'backup' => isset($options['backup-root']) && is_string($options['backup-root'])
                    ? pathEvidence($options['backup-root']) : ['status' => 'UNKNOWN'],
            ],
            'release_marker' => releaseMarker($current),
            'processes' => processEvidence(),
            'cron' => cronEvidence(),
            'backup' => backupEvidence(isset($options['backup-root']) && is_string($options['backup-root']) ? $options['backup-root'] : null),
            'external_writers' => ['status' => 'UNKNOWN'],
            'capabilities' => [
                'release_marker' => 'SUPPORTED_IF_PRESENT',
                'filesystem_topology' => 'SUPPORTED',
                'php_cli_extensions' => 'SUPPORTED',
                'disk_and_permissions' => 'SUPPORTED',
                'queue_worker_process_snapshot' => is_dir('/proc') ? 'SUPPORTED' : 'UNKNOWN',
                'user_cron_snapshot' => function_exists('proc_open') ? 'SUPPORTED_IF_AVAILABLE' : 'UNSUPPORTED',
                'backup_inventory' => 'SUPPORTED_IF_PATH_SUPPLIED',
                'restore_readiness' => 'UNSUPPORTED',
                'external_writers' => 'UNSUPPORTED',
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
} catch (Throwable) {
    failSafe('R0_HOST_AUDIT_FAILED', 'host_audit');
}
