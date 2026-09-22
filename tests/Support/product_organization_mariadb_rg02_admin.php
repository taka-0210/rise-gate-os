<?php

declare(strict_types=1);

/**
 * One-purpose RG02 setup/cleanup helper. It refuses anything except a
 * loopback, run-scoped MariaDB 10.11 schema. Secrets are read from env only.
 */

$mode = $argv[1] ?? '';
$host = getenv('RG02_DB_HOST') ?: '';
$port = (int) (getenv('RG02_DB_PORT') ?: 0);
$schema = getenv('RG02_DB_DATABASE') ?: '';
$runId = getenv('RG02_RUN_ID') ?: '';
$datadir = str_replace('\\', '/', getenv('RG02_DATADIR') ?: '');
$expectedDatadir = str_replace('\\', '/', getenv('RG02_EXPECTED_DATADIR') ?: '');

if ($host !== '127.0.0.1' || $port < 1024 || ! str_starts_with($schema, 'co_rg02_')
    || ! str_starts_with($runId, 'rg02-') || $datadir === '' || $datadir !== $expectedDatadir
    || ! str_contains(strtolower($datadir), 'company-os-rg02-')) {
    fwrite(STDERR, "RG02 admin guard rejected the target.\n");
    exit(64);
}

$root = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=mysql;charset=utf8mb4', $host, $port),
    getenv('RG02_ROOT_USER') ?: 'root',
    getenv('RG02_ROOT_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$identity = $root->query("SELECT VERSION() version, @@datadir datadir")->fetch(PDO::FETCH_ASSOC);
if (! str_starts_with((string) $identity['version'], '10.11.')
    || rtrim(str_replace('\\', '/', (string) $identity['datadir']), '/') !== rtrim($expectedDatadir, '/')) {
    fwrite(STDERR, "RG02 server identity mismatch.\n");
    exit(65);
}

$quote = static fn (string $value): string => $root->quote($value);
$identifier = static fn (string $value): string => '`'.str_replace('`', '``', $value).'`';
$accounts = [
    [getenv('RG02_SETUP_USER') ?: '', getenv('RG02_SETUP_PASSWORD') ?: '', 'ALL PRIVILEGES'],
    [getenv('RG02_WORKER_USER') ?: '', getenv('RG02_WORKER_PASSWORD') ?: '', 'SELECT, INSERT, UPDATE, DELETE'],
    [getenv('RG02_OBSERVER_USER') ?: '', getenv('RG02_OBSERVER_PASSWORD') ?: '', 'SELECT'],
];
foreach ($accounts as [$user]) {
    if (! preg_match('/\A[a-z0-9_]+\z/i', $user)) {
        fwrite(STDERR, "RG02 credential guard rejected an account name.\n");
        exit(66);
    }
}

if ($mode === 'setup') {
    $root->exec('CREATE DATABASE IF NOT EXISTS '.$identifier($schema).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($accounts as [$user, $password, $privileges]) {
        $root->exec('CREATE USER IF NOT EXISTS '.$quote($user).'@'.$quote($host).' IDENTIFIED BY '.$quote($password));
        $root->exec('GRANT '.$privileges.' ON '.$identifier($schema).'.* TO '.$quote($user).'@'.$quote($host));
    }
    $root->exec('FLUSH PRIVILEGES');
    echo json_encode(['status' => 'ready', 'version' => $identity['version'], 'schema' => $schema], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'cleanup') {
    $root->exec('DROP DATABASE IF EXISTS '.$identifier($schema));
    foreach ($accounts as [$user]) {
        $root->exec('DROP USER IF EXISTS '.$quote($user).'@'.$quote($host));
    }
    $root->exec('FLUSH PRIVILEGES');
    echo json_encode(['status' => 'cleaned', 'schema' => $schema], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Unknown RG02 admin mode.\n");
exit(64);
