<?php

namespace App\Services\Release;

use RuntimeException;

class R0AuditBundleVerifier
{
    public const EXACT_CANDIDATE_COMMIT = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    public const OUTPUT_SCHEMA_VERSION = 2;

    private const SOURCE_TREE_EXCLUSIONS = [
        '.env',
        'bootstrap/cache',
        'r0-bundle-manifest.json',
        'r0-migration-manifest.json',
        'storage',
        'vendor',
    ];

    /** @return array<string, mixed> */
    public function verify(string $manifestPath): array
    {
        $manifestRealPath = realpath($manifestPath);
        $bundleRoot = realpath(base_path());
        if ($manifestRealPath === false || $bundleRoot === false || ! is_file($manifestRealPath)) {
            throw new R0AuditSafetyException('R0_BUNDLE_MANIFEST_UNAVAILABLE', 'bundle_preflight');
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $bundleRoot), '/').'/';
        $normalizedManifest = str_replace('\\', '/', $manifestRealPath);
        if (! str_starts_with($normalizedManifest, $normalizedRoot)) {
            throw new R0AuditSafetyException('R0_BUNDLE_MANIFEST_OUTSIDE_ROOT', 'bundle_preflight');
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestRealPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new R0AuditSafetyException('R0_BUNDLE_MANIFEST_INVALID', 'bundle_preflight');
        }

        if (! is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['output_schema_version'] ?? null) !== self::OUTPUT_SCHEMA_VERSION
            || ($manifest['source_commit'] ?? null) !== self::EXACT_CANDIDATE_COMMIT) {
            throw new R0AuditSafetyException('R0_BUNDLE_IDENTITY_MISMATCH', 'bundle_preflight');
        }

        if (is_file($bundleRoot.DIRECTORY_SEPARATOR.'.env')) {
            throw new R0AuditSafetyException('R0_BUNDLE_CONTAINS_ENV_FILE', 'bundle_preflight');
        }

        $this->verifyCriticalFiles($bundleRoot, $manifest['critical_files'] ?? null);
        $this->verifyMigrationManifest($bundleRoot, $manifest);

        try {
            $sourceTreeHash = R0BundleHash::tree($bundleRoot, self::SOURCE_TREE_EXCLUSIONS);
            $vendorTreeHash = R0BundleHash::tree($bundleRoot.DIRECTORY_SEPARATOR.'vendor');
        } catch (RuntimeException) {
            throw new R0AuditSafetyException('R0_BUNDLE_HASH_FAILED', 'bundle_preflight');
        }

        if (! hash_equals((string) ($manifest['source_tree_sha256'] ?? ''), $sourceTreeHash)
            || ! hash_equals((string) ($manifest['vendor_tree_sha256'] ?? ''), $vendorTreeHash)) {
            throw new R0AuditSafetyException('R0_BUNDLE_TREE_HASH_MISMATCH', 'bundle_preflight');
        }

        $expectedBundleId = hash('sha256', implode('|', [
            self::EXACT_CANDIDATE_COMMIT,
            (string) self::OUTPUT_SCHEMA_VERSION,
            $sourceTreeHash,
            $vendorTreeHash,
            (string) $manifest['migration_manifest_sha256'],
            (string) ($manifest['audit_overlay_sha256'] ?? ''),
        ]));
        if (! hash_equals($expectedBundleId, (string) ($manifest['bundle_id'] ?? ''))) {
            throw new R0AuditSafetyException('R0_BUNDLE_ID_MISMATCH', 'bundle_preflight');
        }

        $manifestHash = hash_file('sha256', $manifestRealPath);
        if ($manifestHash === false) {
            throw new R0AuditSafetyException('R0_BUNDLE_MANIFEST_HASH_FAILED', 'bundle_preflight');
        }

        return [
            'source_commit' => self::EXACT_CANDIDATE_COMMIT,
            'bundle_id' => (string) ($manifest['bundle_id'] ?? ''),
            'bundle_manifest_sha256' => $manifestHash,
            'migration_manifest_sha256' => (string) $manifest['migration_manifest_sha256'],
            'output_schema_version' => self::OUTPUT_SCHEMA_VERSION,
            'integrity' => 'PASS',
        ];
    }

    private function verifyCriticalFiles(string $bundleRoot, mixed $criticalFiles): void
    {
        if (! is_array($criticalFiles) || $criticalFiles === []) {
            throw new R0AuditSafetyException('R0_BUNDLE_CRITICAL_FILES_MISSING', 'bundle_preflight');
        }

        foreach ($criticalFiles as $relative => $expectedHash) {
            if (! is_string($relative) || ! is_string($expectedHash) || ! $this->safeRelativePath($relative)) {
                throw new R0AuditSafetyException('R0_BUNDLE_CRITICAL_FILE_INVALID', 'bundle_preflight');
            }

            $path = $bundleRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actualHash === false || ! hash_equals($expectedHash, $actualHash)) {
                throw new R0AuditSafetyException('R0_BUNDLE_CRITICAL_FILE_HASH_MISMATCH', 'bundle_preflight');
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function verifyMigrationManifest(string $bundleRoot, array $manifest): void
    {
        $relative = (string) ($manifest['migration_manifest'] ?? '');
        if (! $this->safeRelativePath($relative)) {
            throw new R0AuditSafetyException('R0_MIGRATION_MANIFEST_INVALID', 'bundle_preflight');
        }

        $path = $bundleRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
        if ($actualHash === false || ! hash_equals((string) ($manifest['migration_manifest_sha256'] ?? ''), $actualHash)) {
            throw new R0AuditSafetyException('R0_MIGRATION_MANIFEST_HASH_MISMATCH', 'bundle_preflight');
        }

        try {
            $migrationManifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new R0AuditSafetyException('R0_MIGRATION_MANIFEST_INVALID', 'bundle_preflight');
        }

        $repository = app(MigrationReleaseGate::class)->repositoryMigrations();
        if (! is_array($migrationManifest)
            || ($migrationManifest['source_commit'] ?? null) !== self::EXACT_CANDIDATE_COMMIT
            || ($migrationManifest['migration_sha256'] ?? null) !== $repository) {
            throw new R0AuditSafetyException('R0_MIGRATION_SET_MISMATCH', 'bundle_preflight');
        }
    }

    private function safeRelativePath(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));

        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! preg_match('/\A[A-Za-z]:\//', $path)
            && ! in_array('..', explode('/', $path), true)
            && ! in_array($path, ['.env', '.git'], true)
            && ! str_starts_with($path, '.git/');
    }
}
