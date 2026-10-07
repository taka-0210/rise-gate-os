<?php

declare(strict_types=1);

/**
 * G5 Primary Production Mailer diagnostic.
 *
 * This program reads the exact Legacy Source .env in process memory and emits
 * stable contract IDs and presence/readiness states only. It never emits an
 * environment key name, environment value, mailer name, credential, raw .env,
 * or a hash of a value/raw .env.
 */

const PRIMARY_MAILER_PRODUCTION_CONFIRMATION = 'IR1-G5-PRIMARY-MAILER-DIAGNOSTIC';
const PRIMARY_MAILER_FIXTURE_CONFIRMATION = 'IR1-G5-PRIMARY-MAILER-DIAGNOSTIC-FIXTURE';
const PRIMARY_MAILER_SOURCE_ENV = '/home/xs257823/rise-gate.com/rise-gate-os/.env';
const PRIMARY_MAILER_SOURCE_HOME = '/home/xs257823';
const PRIMARY_MAILER_SOURCE_UID = 20222;
const PRIMARY_MAILER_SOURCE_GID = 1000;

/** @return never */
function stopPrimaryMailer(string $code): void
{
    echo "G5_PRIMARY_MAILER_DIAGNOSTIC=STOP\n";
    echo "safe_error_code={$code}\n";
    echo "production_change_scope=none_read_only_source\n";
    echo "environment_values_output=false\n";
    echo "primary_mailer_name_output=false\n";
    echo "credential_values_output=false\n";
    echo "key_names_output=false\n";
    echo "raw_env_output=false\n";
    echo "source_env_hash_output=false\n";
    echo "next_action=RETURN_TO_HUMAN_CHATGPT\n";
    exit(1);
}

/** @return array<string, string> */
function parsePrimaryMailerDotEnv(string $contents): array
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

function normalizePrimaryMailerValue(string $raw): string
{
    $value = trim($raw);
    if (preg_match('/^(["\'])(.*)\1(?:\s+#.*)?$/s', $value, $matches) === 1) {
        return trim($matches[2]);
    }
    $value = preg_replace('/(?:^|\s+)#.*$/', '', $value) ?? $value;

    return trim($value);
}

/** @param array<string, string> $values */
function primaryMailerValue(array $values, string $name, ?string $default = null, int $depth = 0): ?string
{
    if ($depth > 4) {
        return null;
    }
    if (! array_key_exists($name, $values)) {
        return $default;
    }
    $value = normalizePrimaryMailerValue($values[$name]);
    if ($value === '' || in_array(strtolower($value), ['null', '(null)', '<null>', 'nil'], true)) {
        return null;
    }
    if (preg_match('/^\$\{([A-Z][A-Z0-9_]*)\}$/', $value, $matches) === 1) {
        return primaryMailerValue($values, $matches[1], null, $depth + 1);
    }

    return $value;
}

/** @param array<string, string> $values */
function requirePrimaryMailerValues(array $values, array $names, string $code): void
{
    foreach ($names as $name) {
        if (primaryMailerValue($values, $name) === null) {
            stopPrimaryMailer($code);
        }
    }
}

/** @param array<string, string> $values */
function validateSmtp(array $values): void
{
    $url = primaryMailerValue($values, 'MAIL_URL');
    if ($url !== null) {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['port'])
            || ! isset($parts['user'], $parts['pass']) || $parts['user'] === '' || $parts['pass'] === '') {
            stopPrimaryMailer('PRIMARY_SMTP_URL_CONTRACT_INCOMPLETE');
        }

        return;
    }

    requirePrimaryMailerValues(
        $values,
        ['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'],
        'PRIMARY_SMTP_CONTRACT_INCOMPLETE',
    );
    $port = primaryMailerValue($values, 'MAIL_PORT');
    if ($port === null || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        stopPrimaryMailer('PRIMARY_SMTP_PORT_INVALID');
    }
}

/** @param array<string, string> $values */
function validateSes(array $values): void
{
    requirePrimaryMailerValues(
        $values,
        ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION'],
        'PRIMARY_SES_CONTRACT_INCOMPLETE',
    );
}

/** @param array<string, string> $values */
function validatePostmark(array $values): void
{
    requirePrimaryMailerValues($values, ['POSTMARK_API_KEY'], 'PRIMARY_POSTMARK_CONTRACT_INCOMPLETE');
}

/** @param array<string, string> $values */
function validateResend(array $values): void
{
    requirePrimaryMailerValues($values, ['RESEND_API_KEY'], 'PRIMARY_RESEND_CONTRACT_INCOMPLETE');
}

/** @param array<string, string> $values */
function validateFromIdentity(array $values): void
{
    $address = primaryMailerValue($values, 'MAIL_FROM_ADDRESS');
    $name = primaryMailerValue($values, 'MAIL_FROM_NAME');
    if ($address === null || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
        stopPrimaryMailer('PRIMARY_FROM_ADDRESS_INVALID');
    }
    $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));
    if ($domain === '' || in_array($domain, ['example.com', 'example.org', 'example.net', 'localhost'], true)
        || str_ends_with($domain, '.invalid')) {
        stopPrimaryMailer('PRIMARY_FROM_ADDRESS_PLACEHOLDER');
    }
    if ($name === null || strtolower($name) === 'laravel') {
        stopPrimaryMailer('PRIMARY_FROM_NAME_INVALID');
    }
}

/**
 * @param array<string, string> $values
 * @return array{0:string,1:string,2:string}
 */
function validateQueue(array $values): array
{
    $queue = strtolower((string) primaryMailerValue($values, 'QUEUE_CONNECTION', 'database'));
    switch ($queue) {
        case 'sync':
            return ['q01', 'not_required', 'tc00'];
        case 'database':
            requirePrimaryMailerValues(
                $values,
                ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
                'PRIMARY_QUEUE_DATABASE_CONTRACT_INCOMPLETE',
            );

            return ['q02', 'required', 'tc00'];
        case 'beanstalkd':
            if (primaryMailerValue($values, 'BEANSTALKD_QUEUE_HOST', 'localhost') === null) {
                stopPrimaryMailer('PRIMARY_QUEUE_BEANSTALKD_CONTRACT_INCOMPLETE');
            }

            return ['q03', 'required', 'tc00'];
        case 'sqs':
            requirePrimaryMailerValues(
                $values,
                ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'SQS_PREFIX', 'SQS_QUEUE'],
                'PRIMARY_QUEUE_SQS_CONTRACT_INCOMPLETE',
            );
            if (str_contains((string) primaryMailerValue($values, 'SQS_PREFIX'), 'your-account-id')) {
                stopPrimaryMailer('PRIMARY_QUEUE_SQS_PLACEHOLDER');
            }

            return ['q04', 'required', 'tc00'];
        case 'redis':
            if (primaryMailerValue($values, 'REDIS_HOST', '127.0.0.1') === null) {
                stopPrimaryMailer('PRIMARY_QUEUE_REDIS_CONTRACT_INCOMPLETE');
            }

            return ['q05', 'required', 'tc00'];
        case 'deferred':
            return ['q06', 'not_required', 'tc00'];
        case 'background':
            return ['q07', 'not_required', 'tc02'];
        case 'failover':
            requirePrimaryMailerValues(
                $values,
                ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
                'PRIMARY_QUEUE_FAILOVER_CONTRACT_INCOMPLETE',
            );

            return ['q08', 'conditional', 'tc00'];
        default:
            stopPrimaryMailer('PRIMARY_QUEUE_DRIVER_UNSUPPORTED');
    }
}

$confirmation = $argv[1] ?? '';
$sourceEnv = $argv[2] ?? '';
if (! in_array($confirmation, [PRIMARY_MAILER_PRODUCTION_CONFIRMATION, PRIMARY_MAILER_FIXTURE_CONFIRMATION], true)) {
    stopPrimaryMailer('CONFIRMATION_MISMATCH');
}
if ($confirmation === PRIMARY_MAILER_PRODUCTION_CONFIRMATION) {
    if ($sourceEnv !== PRIMARY_MAILER_SOURCE_ENV) {
        stopPrimaryMailer('SOURCE_ENV_BINDING_MISMATCH');
    }
    if (getenv('HOME') !== PRIMARY_MAILER_SOURCE_HOME
        || ! function_exists('posix_geteuid') || ! function_exists('posix_getegid')
        || posix_geteuid() !== PRIMARY_MAILER_SOURCE_UID || posix_getegid() !== PRIMARY_MAILER_SOURCE_GID) {
        stopPrimaryMailer('SOURCE_IDENTITY_BINDING_MISMATCH');
    }
}
if ($sourceEnv === '' || ! is_file($sourceEnv) || is_link($sourceEnv) || ! is_readable($sourceEnv)) {
    stopPrimaryMailer('SOURCE_ENV_UNAVAILABLE');
}

$contents = file_get_contents($sourceEnv);
if ($contents === false) {
    stopPrimaryMailer('SOURCE_ENV_READ_FAILED');
}
$values = parsePrimaryMailerDotEnv($contents);
unset($contents);

$primaryMailer = strtolower((string) primaryMailerValue($values, 'MAIL_MAILER', 'log'));
$mailerId = '';
$targetCapabilityId = 'tc00';
$endpointPresence = 'complete';
$credentialPresence = 'complete';
switch ($primaryMailer) {
    case 'smtp':
        validateSmtp($values);
        $mailerId = 'mt01';
        break;
    case 'ses':
        validateSes($values);
        $mailerId = 'mt02';
        break;
    case 'postmark':
        validatePostmark($values);
        $mailerId = 'mt03';
        break;
    case 'resend':
        validateResend($values);
        $mailerId = 'mt04';
        break;
    case 'sendmail':
        $sendmail = primaryMailerValue($values, 'MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i');
        if ($sendmail !== '/usr/sbin/sendmail -bs -i') {
            stopPrimaryMailer('PRIMARY_SENDMAIL_CUSTOM_PATH_UNSUPPORTED');
        }
        $mailerId = 'mt05';
        $targetCapabilityId = 'tc01';
        $endpointPresence = 'not_applicable';
        $credentialPresence = 'not_applicable';
        break;
    case 'failover':
        stopPrimaryMailer('PRIMARY_FAILOVER_INCLUDES_UNSAFE_FALLBACK');
    case 'roundrobin':
        validateSes($values);
        validatePostmark($values);
        $mailerId = 'mt07';
        break;
    case 'log':
    case 'array':
        stopPrimaryMailer('PRIMARY_MAILER_UNSAFE');
    default:
        stopPrimaryMailer('PRIMARY_MAILER_UNSUPPORTED');
}

validateFromIdentity($values);
[$queueId, $workerDependency, $queueTargetCapabilityId] = validateQueue($values);
if ($targetCapabilityId === 'tc00') {
    $targetCapabilityId = $queueTargetCapabilityId;
} elseif ($queueTargetCapabilityId === 'tc02') {
    $targetCapabilityId = 'tc03';
}
unset($values, $primaryMailer);

echo "G5_PRIMARY_MAILER_DIAGNOSTIC=PASS\n";
echo "primary_mailer_id={$mailerId}\n";
echo "primary_transport_state=delivery_capable\n";
echo "primary_endpoint_presence={$endpointPresence}\n";
echo "primary_credential_presence={$credentialPresence}\n";
echo "from_address_state=valid\n";
echo "from_name_state=present\n";
echo "account_mail_binding=primary_exact\n";
echo "account_mail_value_source=derived_in_memory\n";
echo "account_mail_manual_input=false\n";
echo "queue_driver_id={$queueId}\n";
echo "queue_config_state=complete\n";
echo "queue_worker_dependency={$workerDependency}\n";
echo "queue_runtime_readiness=deferred_application_release_gate\n";
echo "target_capability_id={$targetCapabilityId}\n";
echo "production_change_scope=none_read_only_source\n";
echo "environment_values_output=false\n";
echo "primary_mailer_name_output=false\n";
echo "credential_values_output=false\n";
echo "key_names_output=false\n";
echo "raw_env_output=false\n";
echo "source_env_hash_output=false\n";
echo "next_action=RETURN_TO_HUMAN_CHATGPT\n";
