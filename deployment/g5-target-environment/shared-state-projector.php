<?php

declare(strict_types=1);

function stop(string $header, string $code): never
{
    echo $header."=STOP\n";
    echo "safe_error_code={$code}\n";
    echo "secret_output=false\n";
    echo "next_action=RETURN_TO_HUMAN_CHATGPT\n";
    exit(1);
}

function simple_value(string $raw): string
{
    $value = trim($raw);
    $length = strlen($value);
    if ($length >= 2 && (($value[0] === '"' && $value[$length - 1] === '"')
        || ($value[0] === "'" && $value[$length - 1] === "'"))) {
        return substr($value, 1, -1);
    }

    return $value;
}

function meaningful(array $values, string $key): bool
{
    if (! array_key_exists($key, $values)) {
        return false;
    }
    $value = strtolower(simple_value($values[$key]));

    return ! in_array($value, ['', 'null', '(null)', 'empty', '(empty)'], true);
}

function parse_environment(string $path, string $header): array
{
    if (! is_file($path) || is_link($path) || ! is_readable($path)) {
        stop($header, 'ENV_SOURCE_INVALID');
    }
    $content = file_get_contents($path);
    if ($content === false || str_contains($content, "\0") || preg_match('/\r(?!\n)/', $content)) {
        stop($header, 'ENV_SOURCE_ENCODING_INVALID');
    }
    $normalized = str_replace("\r\n", "\n", $content);
    $values = [];
    foreach (explode("\n", $normalized) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (! preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$/D', $line, $matches)) {
            stop($header, 'ENV_SOURCE_NONCANONICAL_LINE');
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            stop($header, 'ENV_SOURCE_DUPLICATE_KEY');
        }
        $values[$key] = $matches[2];
    }

    return [$content, $values];
}

function decode_allowlist(string $encoded, string $header): array
{
    $raw = base64_decode($encoded, true);
    if ($raw === false || ! str_ends_with($raw, "\n")) {
        stop($header, 'ALLOWLIST_DECODE_FAILED');
    }
    $allowlist = array_values(array_filter(
        explode("\n", $raw),
        static fn (string $key): bool => $key !== ''
    ));
    $sorted = $allowlist;
    sort($sorted, SORT_STRING);
    if (count($allowlist) !== 172 || count(array_unique($allowlist)) !== 172 || $sorted !== $allowlist
        || hash('sha256', $raw) !== 'd408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec') {
        stop($header, 'ALLOWLIST_IDENTITY_MISMATCH');
    }

    return [$raw, $allowlist];
}

function build_target_payload(array $sourceValues, array $allowlist, string $header): array
{
    $allowed = array_fill_keys($allowlist, true);
    $selected = [];
    $unknownCount = 0;
    foreach ($sourceValues as $key => $rawValue) {
        if (isset($allowed[$key])) {
            $selected[$key] = $rawValue;
        } else {
            $unknownCount++;
        }
    }

    $required = [
        'APP_KEY',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'MAIL_MAILER',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'ACCOUNT_MAIL_MAILER',
        'OPENAI_API_KEY',
    ];
    foreach ($required as $key) {
        if (! meaningful($selected, $key)) {
            stop($header, 'REQUIRED_SOURCE_KEY_MISSING');
        }
    }
    $databaseConnection = strtolower(simple_value($selected['DB_CONNECTION']));
    if (! in_array($databaseConnection, ['mysql', 'mariadb'], true)) {
        stop($header, 'DATABASE_DRIVER_UNSUPPORTED');
    }
    $accountMailer = strtolower(simple_value($selected['ACCOUNT_MAIL_MAILER']));
    if (in_array($accountMailer, ['', 'log', 'array'], true)) {
        stop($header, 'ACCOUNT_MAILER_UNSAFE');
    }
    if ($accountMailer === 'smtp' && (! meaningful($selected, 'MAIL_HOST') || ! meaningful($selected, 'MAIL_PORT'))) {
        stop($header, 'SMTP_CONTRACT_INCOMPLETE');
    }
    if ($accountMailer === 'postmark' && ! meaningful($selected, 'POSTMARK_API_KEY')) {
        stop($header, 'POSTMARK_CONTRACT_INCOMPLETE');
    }
    if ($accountMailer === 'resend' && ! meaningful($selected, 'RESEND_API_KEY')) {
        stop($header, 'RESEND_CONTRACT_INCOMPLETE');
    }
    if ($accountMailer === 'ses' && (! meaningful($selected, 'AWS_ACCESS_KEY_ID')
        || ! meaningful($selected, 'AWS_SECRET_ACCESS_KEY') || ! meaningful($selected, 'AWS_DEFAULT_REGION'))) {
        stop($header, 'SES_CONTRACT_INCOMPLETE');
    }

    $fixed = [
        'APP_URL' => 'https://app.company-os.jp',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_TIMEZONE' => 'Asia/Tokyo',
        'APP_DISPLAY_TIMEZONE' => 'Asia/Tokyo',
        'SESSION_DOMAIN' => 'null',
        'SESSION_SECURE_COOKIE' => 'true',
        'PRODUCT_UX_OPERATION_FIXTURE_SEED_ENABLED' => 'false',
    ];
    foreach ($fixed as $key => $rawValue) {
        $selected[$key] = $rawValue;
    }
    ksort($selected, SORT_STRING);
    $payload = '';
    foreach ($selected as $key => $rawValue) {
        $payload .= $key.'='.$rawValue."\n";
    }

    return [$payload, count($selected), $unknownCount, count($required), count($fixed)];
}

function storage_manifest(string $root, string $header): array
{
    $root = realpath($root);
    if ($root === false || ! is_dir($root) || is_link($root)) {
        stop($header, 'STORAGE_APP_REALPATH_FAILED');
    }
    $entries = [];
    $fileCount = 0;
    $directoryCount = 0;
    $totalBytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $absolute = $item->getPathname();
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
        if ($relative === '' || preg_match('/[\x00-\x1f\x7f]/', $relative)) {
            stop($header, 'STORAGE_PATH_INVALID');
        }
        if ($item->isLink()) {
            stop($header, 'STORAGE_SYMLINK_FORBIDDEN');
        }
        if ($item->isDir()) {
            $entries[] = "d\0{$relative}\0\n";
            $directoryCount++;
            continue;
        }
        if (! $item->isFile()) {
            stop($header, 'STORAGE_SPECIAL_ENTRY_FORBIDDEN');
        }
        $size = $item->getSize();
        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            stop($header, 'STORAGE_FILE_HASH_FAILED');
        }
        $entries[] = "f\0{$relative}\0{$size}\0{$hash}\n";
        $fileCount++;
        $totalBytes += $size;
    }
    sort($entries, SORT_STRING);

    return [hash('sha256', implode('', $entries)), $fileCount, $directoryCount, $totalBytes];
}

$mode = $argv[1] ?? '';
$header = match ($mode) {
    'inspect-source' => 'G5_SHARED_SOURCE_INVENTORY',
    'project-target' => 'G5_SHARED_ENV_PROJECTION',
    'inspect-target-storage' => 'G5_SHARED_TARGET_STORAGE',
    default => 'G5_SHARED_PROJECTOR',
};

if ($mode === 'inspect-source') {
    if (count($argv) !== 5) {
        stop($header, 'ARGUMENT_CONTRACT_FAILED');
    }
    [$allowlistRaw, $allowlist] = decode_allowlist($argv[4], $header);
    [$sourceContent, $sourceValues] = parse_environment($argv[2], $header);
    [$payload, $selectedCount, $unknownCount, $requiredCount, $fixedCount] = build_target_payload(
        $sourceValues,
        $allowlist,
        $header
    );
    [$storageHash, $storageFiles, $storageDirectories, $storageBytes] = storage_manifest($argv[3], $header);

    echo "{$header}=PASS\n";
    echo 'candidate_allowlist_count='.count($allowlist)."\n";
    echo 'candidate_allowlist_sha256='.hash('sha256', $allowlistRaw)."\n";
    echo 'source_env_sha256='.hash('sha256', $sourceContent)."\n";
    echo 'source_key_count='.count($sourceValues)."\n";
    echo "selected_key_count={$selectedCount}\n";
    echo "unknown_key_count={$unknownCount}\n";
    echo "required_key_count={$requiredCount}\n";
    echo "target_fixed_key_count={$fixedCount}\n";
    echo 'env_payload_sha256='.hash('sha256', $payload)."\n";
    echo 'env_payload_bytes='.strlen($payload)."\n";
    echo "target_app_url_binding=app.company-os.jp\n";
    echo "target_environment=production\n";
    echo "target_debug=false\n";
    echo "target_timezone=Asia_Tokyo\n";
    echo "target_session_secure_cookie=true\n";
    echo "target_fixture_seed=false\n";
    echo "storage_seed_scope=storage_app_only\n";
    echo "storage_app_manifest_sha256={$storageHash}\n";
    echo "storage_app_file_count={$storageFiles}\n";
    echo "storage_app_directory_count={$storageDirectories}\n";
    echo "storage_app_total_bytes={$storageBytes}\n";
    echo "storage_symlink_count=0\n";
    echo "storage_special_entry_count=0\n";
    echo "production_change_scope=none_read_only_source\n";
    echo "secret_output=false\n";
    echo "next_action=CONTINUE_SAME_AUTHORIZED_HELPER\n";
    exit(0);
}

if ($mode === 'project-target') {
    if (count($argv) !== 8) {
        stop($header, 'ARGUMENT_CONTRACT_FAILED');
    }
    if (! is_file($argv[2]) || is_link($argv[2]) || ! chmod($argv[2], 0600)) {
        stop($header, 'SOURCE_ENV_STAGING_MODE_FAILED');
    }
    clearstatcache(true, $argv[2]);
    if (substr(sprintf('%o', fileperms($argv[2])), -4) !== '0600') {
        stop($header, 'SOURCE_ENV_STAGING_MODE_VERIFY_FAILED');
    }
    [, $allowlist] = decode_allowlist($argv[4], $header);
    [$sourceContent, $sourceValues] = parse_environment($argv[2], $header);
    if (! hash_equals($argv[7], hash('sha256', $sourceContent))) {
        stop($header, 'SOURCE_ENV_COPY_DRIFT');
    }
    [$payload, $selectedCount, $unknownCount, $requiredCount, $fixedCount] = build_target_payload(
        $sourceValues,
        $allowlist,
        $header
    );
    $payloadHash = hash('sha256', $payload);
    $payloadBytes = strlen($payload);
    if (! hash_equals($argv[5], $payloadHash) || (string) $payloadBytes !== $argv[6]) {
        stop($header, 'ENV_PAYLOAD_SOURCE_DRIFT');
    }
    if (file_exists($argv[3]) || is_link($argv[3])) {
        stop($header, 'TARGET_ENV_STAGING_COLLISION');
    }
    $handle = @fopen($argv[3], 'x+b');
    if ($handle === false) {
        stop($header, 'TARGET_ENV_STAGING_CREATE_FAILED');
    }
    $written = fwrite($handle, $payload);
    $flushed = fflush($handle);
    fclose($handle);
    if ($written !== $payloadBytes || ! $flushed || ! chmod($argv[3], 0600)) {
        stop($header, 'TARGET_ENV_STAGING_WRITE_FAILED');
    }
    clearstatcache(true, $argv[3]);
    if (! is_file($argv[3]) || is_link($argv[3]) || substr(sprintf('%o', fileperms($argv[3])), -4) !== '0600'
        || hash_file('sha256', $argv[3]) !== $payloadHash) {
        stop($header, 'TARGET_ENV_STAGING_VERIFY_FAILED');
    }

    echo "{$header}=PASS\n";
    echo "selected_key_count={$selectedCount}\n";
    echo "unknown_key_count={$unknownCount}\n";
    echo "required_key_count={$requiredCount}\n";
    echo "target_fixed_key_count={$fixedCount}\n";
    echo 'source_env_sha256='.hash('sha256', $sourceContent)."\n";
    echo "env_payload_sha256={$payloadHash}\n";
    echo "env_payload_bytes={$payloadBytes}\n";
    echo "target_env_mode=0600\n";
    echo "target_env_values_output=false\n";
    echo "secret_output=false\n";
    echo "next_action=CONTINUE_SAME_AUTHORIZED_HELPER\n";
    exit(0);
}

if ($mode === 'inspect-target-storage') {
    if (count($argv) !== 7) {
        stop($header, 'ARGUMENT_CONTRACT_FAILED');
    }
    [$storageHash, $storageFiles, $storageDirectories, $storageBytes] = storage_manifest($argv[2], $header);
    if (! hash_equals($argv[3], $storageHash) || (string) $storageFiles !== $argv[4]
        || (string) $storageDirectories !== $argv[5] || (string) $storageBytes !== $argv[6]) {
        stop($header, 'TARGET_STORAGE_SEED_MISMATCH');
    }
    echo "{$header}=PASS\n";
    echo "storage_app_manifest_sha256={$storageHash}\n";
    echo "storage_app_file_count={$storageFiles}\n";
    echo "storage_app_directory_count={$storageDirectories}\n";
    echo "storage_app_total_bytes={$storageBytes}\n";
    echo "storage_symlink_count=0\n";
    echo "storage_special_entry_count=0\n";
    echo "secret_output=false\n";
    echo "next_action=CONTINUE_SAME_AUTHORIZED_HELPER\n";
    exit(0);
}

stop($header, 'MODE_INVALID');
