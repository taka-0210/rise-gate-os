<?php

declare(strict_types=1);

use App\Services\Release\R0BundleHash;

const R0_BUILD_CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';
const R0_BUILD_OUTPUT_SCHEMA = 2;
const R0_BUILD_OVERLAYS = [
    'app/Console/Commands/AuditProductionCurrentState.php',
    'app/Services/Release/ProductionReadOnlyAudit.php',
    'app/Services/Release/R0AuditBundleVerifier.php',
    'app/Services/Release/R0AuditSafetyException.php',
    'app/Services/Release/R0BundleHash.php',
    'app/Services/Release/ReadOnlySqlGuard.php',
    'deployment/r0-audit/r0-artisan.php',
    'deployment/r0-audit/r0-host-audit.php',
    'deployment/r0-audit/R0_AUDIT_PROCEDURE.md',
];
const R0_BUILD_SOURCE_EXCLUSIONS = [
    '.env',
    'bootstrap/cache',
    'r0-bundle-manifest.json',
    'r0-migration-manifest.json',
    'storage',
    'vendor',
];

if ($argc !== 3 || $argv[2] !== R0_BUILD_CANDIDATE) {
    fwrite(STDERR, 'Usage: php build-bundle-manifest.php <bundle-root> '.R0_BUILD_CANDIDATE.PHP_EOL);
    exit(2);
}

$root = realpath($argv[1]);
if ($root === false || ! is_dir($root) || is_file($root.DIRECTORY_SEPARATOR.'.env')) {
    fwrite(STDERR, "Bundle root is invalid or contains .env.\n");
    exit(2);
}

require_once $root.'/app/Services/Release/R0BundleHash.php';

$migrationHashes = [];
foreach (glob($root.'/database/migrations/*.php') ?: [] as $path) {
    $migrationHashes[pathinfo($path, PATHINFO_FILENAME)] = hash_file('sha256', $path);
}
ksort($migrationHashes);
$migrationRepositoryHash = hash('sha256', json_encode($migrationHashes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$migrationManifest = [
    'schema_version' => 1,
    'source_commit' => R0_BUILD_CANDIDATE,
    'migration_count' => count($migrationHashes),
    'repository_migrations_sha256' => $migrationRepositoryHash,
    'migration_sha256' => $migrationHashes,
];
$migrationManifestPath = $root.'/r0-migration-manifest.json';
file_put_contents($migrationManifestPath, json_encode($migrationManifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
$migrationManifestHash = hash_file('sha256', $migrationManifestPath);

$overlayHashes = [];
foreach (R0_BUILD_OVERLAYS as $relative) {
    $path = $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (! is_file($path)) {
        fwrite(STDERR, "Required overlay is missing.\n");
        exit(3);
    }
    $overlayHashes[$relative] = hash_file('sha256', $path);
}
ksort($overlayHashes);
$overlayHash = hash('sha256', json_encode($overlayHashes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

$criticalPaths = array_merge([
    'artisan',
    'bootstrap/app.php',
    'composer.lock',
], R0_BUILD_OVERLAYS);
foreach (['bootstrap/cache/packages.php', 'bootstrap/cache/services.php'] as $cachePath) {
    if (is_file($root.'/'.$cachePath)) {
        $criticalPaths[] = $cachePath;
    }
}
$criticalFiles = [];
foreach ($criticalPaths as $relative) {
    $criticalFiles[$relative] = hash_file('sha256', $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative));
}
ksort($criticalFiles);

$sourceTreeHash = R0BundleHash::tree($root, R0_BUILD_SOURCE_EXCLUSIONS);
$vendorTreeHash = R0BundleHash::tree($root.'/vendor');
$bundleId = hash('sha256', implode('|', [
    R0_BUILD_CANDIDATE,
    (string) R0_BUILD_OUTPUT_SCHEMA,
    $sourceTreeHash,
    $vendorTreeHash,
    $migrationManifestHash,
    $overlayHash,
]));

$manifest = [
    'schema_version' => 1,
    'output_schema_version' => R0_BUILD_OUTPUT_SCHEMA,
    'source_commit' => R0_BUILD_CANDIDATE,
    'bundle_id' => $bundleId,
    'source_tree_sha256' => $sourceTreeHash,
    'vendor_tree_sha256' => $vendorTreeHash,
    'audit_overlay_sha256' => $overlayHash,
    'overlay_files' => $overlayHashes,
    'migration_manifest' => 'r0-migration-manifest.json',
    'migration_manifest_sha256' => $migrationManifestHash,
    'critical_files' => $criticalFiles,
    'expected_command_script_set' => [
        'application_db' => 'deployment/r0-audit/r0-artisan.php release:audit-r0',
        'host' => 'deployment/r0-audit/r0-host-audit.php',
    ],
    'capability_contract' => [
        'application_db' => 'SUPPORTED',
        'filesystem_topology' => 'SUPPORTED',
        'process_snapshot' => 'SUPPORTED_IF_PROC_AVAILABLE',
        'user_cron_snapshot' => 'SUPPORTED_IF_CRONTAB_AVAILABLE',
        'backup_inventory' => 'SUPPORTED_IF_PATH_SUPPLIED',
        'restore_readiness' => 'UNSUPPORTED',
        'external_writers' => 'UNSUPPORTED',
    ],
];
$manifestPath = $root.'/r0-bundle-manifest.json';
file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

fwrite(STDOUT, json_encode([
    'bundle_id' => $bundleId,
    'source_commit' => R0_BUILD_CANDIDATE,
    'migration_count' => count($migrationHashes),
    'bundle_manifest_sha256' => hash_file('sha256', $manifestPath),
    'migration_manifest_sha256' => $migrationManifestHash,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
