<?php

declare(strict_types=1);

/**
 * G5 Shared State required-source diagnostic.
 *
 * This program is intentionally read-only. It reports only stable diagnostic
 * IDs and one of four value states. It never prints an environment key name,
 * an environment value, the raw .env, or a hash of the raw .env.
 */

const PRODUCTION_CONFIRMATION = 'IR1-G5-SHARED-REQUIRED-DIAGNOSTIC';
const FIXTURE_CONFIRMATION = 'IR1-G5-SHARED-REQUIRED-DIAGNOSTIC-FIXTURE';
const PRODUCTION_SOURCE_ENV = '/home/xs257823/rise-gate.com/rise-gate-os/.env';
const PRODUCTION_SOURCE_HOME = '/home/xs257823';
const PRODUCTION_SOURCE_UID = 20222;
const PRODUCTION_SOURCE_GID = 1000;

/** @return never */
function stopDiagnostic(string $code): void
{
    echo "G5_SHARED_REQUIRED_DIAGNOSTIC=STOP\n";
    echo "safe_error_code={$code}\n";
    echo "secret_output=false\n";
    echo "key_names_output=false\n";
    echo "raw_env_output=false\n";
    echo "source_env_hash_output=false\n";
    echo "production_change_scope=none_read_only_source\n";
    echo "next_action=RETURN_TO_HUMAN_CHATGPT\n";
    exit(1);
}

/** @return array<string, string> */
function parseDotEnv(string $contents): array
{
    $values = [];
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = ltrim(substr($trimmed, 7));
        }
        $separator = strpos($trimmed, '=');
        if ($separator === false) {
            continue;
        }
        $name = trim(substr($trimmed, 0, $separator));
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            continue;
        }
        $values[$name] = trim(substr($trimmed, $separator + 1));
    }

    return $values;
}

function normalizedValue(string $raw): string
{
    $value = trim($raw);
    if (preg_match('/^(["\'])(.*)\1(?:\s+#.*)?$/s', $value, $matches) === 1) {
        return trim($matches[2]);
    }

    $value = preg_replace('/(?:^|\s+)#.*$/', '', $value) ?? $value;

    return trim($value);
}

$confirmation = $argv[1] ?? '';
$sourceEnv = $argv[2] ?? '';

if (! in_array($confirmation, [PRODUCTION_CONFIRMATION, FIXTURE_CONFIRMATION], true)) {
    stopDiagnostic('CONFIRMATION_MISMATCH');
}
if ($confirmation === PRODUCTION_CONFIRMATION && $sourceEnv !== PRODUCTION_SOURCE_ENV) {
    stopDiagnostic('SOURCE_ENV_BINDING_MISMATCH');
}
if ($confirmation === PRODUCTION_CONFIRMATION) {
    if (getenv('HOME') !== PRODUCTION_SOURCE_HOME
        || ! function_exists('posix_geteuid')
        || ! function_exists('posix_getegid')
        || posix_geteuid() !== PRODUCTION_SOURCE_UID
        || posix_getegid() !== PRODUCTION_SOURCE_GID) {
        stopDiagnostic('SOURCE_IDENTITY_BINDING_MISMATCH');
    }
}
if ($sourceEnv === '' || ! is_file($sourceEnv) || is_link($sourceEnv) || ! is_readable($sourceEnv)) {
    stopDiagnostic('SOURCE_ENV_UNAVAILABLE');
}

$contents = file_get_contents($sourceEnv);
if ($contents === false) {
    stopDiagnostic('SOURCE_ENV_READ_FAILED');
}

$values = parseDotEnv($contents);
unset($contents);

$required = [
    'rk01' => 'APP_KEY',
    'rk02' => 'DB_CONNECTION',
    'rk03' => 'DB_HOST',
    'rk04' => 'DB_PORT',
    'rk05' => 'DB_DATABASE',
    'rk06' => 'DB_USERNAME',
    'rk07' => 'DB_PASSWORD',
    'rk08' => 'MAIL_MAILER',
    'rk09' => 'MAIL_FROM_ADDRESS',
    'rk10' => 'MAIL_FROM_NAME',
    'rk11' => 'ACCOUNT_MAIL_MAILER',
    'rk12' => 'OPENAI_API_KEY',
];

$states = [];
$counts = ['missing' => 0, 'empty' => 0, 'null_equivalent' => 0, 'present' => 0];
$failing = [];
$nullEquivalents = ['null', '(null)', '<null>', 'nil'];

foreach ($required as $id => $name) {
    if (! array_key_exists($name, $values)) {
        $state = 'missing';
    } else {
        $normalized = normalizedValue($values[$name]);
        if ($normalized === '') {
            $state = 'empty';
        } elseif (in_array(strtolower($normalized), $nullEquivalents, true)) {
            $state = 'null_equivalent';
        } else {
            $state = 'present';
        }
    }
    $states[$id] = $state;
    $counts[$state]++;
    if ($state !== 'present') {
        $failing[] = "{$id}:{$state}";
    }
}

unset($values, $required);
sort($failing, SORT_STRING);
$failingPayload = $failing === [] ? '' : implode("\n", $failing)."\n";

echo "G5_SHARED_REQUIRED_DIAGNOSTIC=PASS\n";
foreach ($states as $id => $state) {
    echo "{$id}_state={$state}\n";
}
echo 'required_id_count='.count($states)."\n";
echo "missing_count={$counts['missing']}\n";
echo "empty_count={$counts['empty']}\n";
echo "null_equivalent_count={$counts['null_equivalent']}\n";
echo "present_count={$counts['present']}\n";
echo 'failing_id_set_sha256='.hash('sha256', $failingPayload)."\n";
echo "dotenv_parse=complete\n";
echo "storage_inventory=not_attempted\n";
echo "new_target_connection=not_attempted\n";
echo "secret_output=false\n";
echo "key_names_output=false\n";
echo "raw_env_output=false\n";
echo "source_env_hash_output=false\n";
echo "source_identity_binding=exact_legacy_source\n";
echo "production_change_scope=none_read_only_source\n";
echo "next_action=RETURN_TO_HUMAN_CHATGPT\n";
