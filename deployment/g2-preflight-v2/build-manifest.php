<?php

declare(strict_types=1);

const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';
const R0_BUNDLE_SHA256 = 'a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d';
const R0_MANIFEST_SHA256 = 'a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7';

$root = realpath($argv[1] ?? '');
if ($root === false || ! is_file($root.'/launcher.sh') || ! is_file($root.'/auditor.php')) {
    fwrite(STDERR, "Invalid v2 artifact stage.\n");
    exit(2);
}

$files = ['auditor.php', 'launcher.sh'];
$hashes = [];
foreach ($files as $file) {
    $hashes[$file] = hash_file('sha256', $root.'/'.$file);
}
ksort($hashes);
$manifest = [
    'schema_version' => 2,
    'output_schema_version' => 2,
    'source_commit' => CANDIDATE,
    'artifact_type' => 'ir1-g2-migration-safety-preflight-v2',
    'r0_bundle_sha256' => R0_BUNDLE_SHA256,
    'r0_bundle_manifest_sha256' => R0_MANIFEST_SHA256,
    'expected_file_set' => array_keys($hashes),
    'expected_archive_file_set' => ['auditor.php', 'checksums.sha256', 'launcher.sh', 'manifest.json'],
    'expected_command_script_set' => [
        'shell_launcher' => 'launcher.sh <exact-r0-bundle-root> <approved-env-file>',
        'php_auditor' => 'auditor.php',
    ],
    'file_sha256' => $hashes,
    'execution_contract' => [
        'application_bootstrap' => false,
        'database_statement_classes' => ['SELECT'],
        'sql_statement_limit' => 24,
        'persistent_db_write' => false,
        'ddl' => false,
        'migration_execution' => false,
        'remote_file_mutation_during_execution' => false,
    ],
    'gate_sequence' => ['ARTIFACT_REVIEW', 'PRODUCTION_PLACEMENT', 'READ_ONLY_EXECUTION'],
];
file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
$checksumLines = [];
foreach (['auditor.php', 'launcher.sh', 'manifest.json'] as $file) {
    $checksumLines[] = hash_file('sha256', $root.'/'.$file).'  '.$file;
}
file_put_contents($root.'/checksums.sha256', implode("\n", $checksumLines)."\n");
fwrite(STDOUT, json_encode([
    'status' => 'PASS',
    'source_commit' => CANDIDATE,
    'manifest_sha256' => hash_file('sha256', $root.'/manifest.json'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
