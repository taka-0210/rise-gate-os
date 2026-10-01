<?php

namespace App\Console\Commands;

use App\Services\Release\ProductionReadOnlyAudit;
use App\Services\Release\R0AuditBundleVerifier;
use App\Services\Release\R0AuditSafetyException;
use Illuminate\Console\Command;

class AuditProductionCurrentState extends Command
{
    protected $signature = 'release:audit-r0 {--confirm-read-only=} {--bundle-manifest=}';

    protected $description = 'Collect sanitized G01/G02 current-state evidence using SELECT and metadata reads only.';

    public function handle(ProductionReadOnlyAudit $audit, R0AuditBundleVerifier $bundleVerifier): int
    {
        if ((string) $this->option('confirm-read-only') !== 'IR1-R0-READ-ONLY') {
            $this->emitFailure('R0_READ_ONLY_CONFIRMATION_REQUIRED', 'command_preflight');

            return self::INVALID;
        }

        try {
            $manifestPath = trim((string) $this->option('bundle-manifest'));
            if ($manifestPath === '') {
                throw new R0AuditSafetyException('R0_BUNDLE_MANIFEST_REQUIRED', 'bundle_preflight');
            }

            $bundleIdentity = $bundleVerifier->verify($manifestPath);
            $evidence = $audit->collect($bundleIdentity);
            $this->line(json_encode([
                'output_schema_version' => R0AuditBundleVerifier::OUTPUT_SCHEMA_VERSION,
                'status' => 'PASS',
                'audit_mode' => 'read-only',
                'evidence_completeness' => 'complete_for_supported_capabilities',
                'failure' => null,
                'evidence' => $evidence,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (R0AuditSafetyException $exception) {
            $this->emitFailure($exception->safeErrorCode(), $exception->failureStage());
        } catch (\Throwable) {
            $this->emitFailure('R0_APPLICATION_AUDIT_FAILED', 'application_audit');
        }

        return self::FAILURE;
    }

    private function emitFailure(string $safeErrorCode, string $failureStage): void
    {
        $this->line(json_encode([
            'output_schema_version' => R0AuditBundleVerifier::OUTPUT_SCHEMA_VERSION,
            'status' => 'INCONCLUSIVE',
            'audit_mode' => 'read-only',
            'evidence_completeness' => 'incomplete',
            'failure' => [
                'safe_error_code' => $safeErrorCode,
                'failure_stage' => $failureStage,
            ],
            'evidence' => null,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
