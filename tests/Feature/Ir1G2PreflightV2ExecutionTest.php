<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Ir1G2PreflightV2ExecutionTest extends TestCase
{
    private const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    private string $helper;

    private string $evidenceRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = base_path('deployment/g2-preflight-v2/Invoke-G2PreflightV2Execution.ps1');
        $this->evidenceRoot = storage_path('app/release-audit/production-g2-preflight-v2-execution-'.self::CANDIDATE.'-corrective-1');
        $this->assertDirectoryDoesNotExist($this->evidenceRoot, 'Corrective execution attempt state must not exist before verification.');
    }

    public function test_execution_helper_is_one_shot_candidate_bound_and_remote_read_only(): void
    {
        $source = (string) file_get_contents($this->helper);

        $this->assertStringContainsString(self::CANDIDATE, $source);
        $this->assertStringContainsString('PlacementStateSha256', $source);
        $this->assertStringContainsString('6b9af42c593ad52697cf3223cf3f644aec22f77321f7a1416c2890f9fc90a3b4', $source);
        $this->assertStringContainsString('InitialStopEvidenceSha256', $source);
        $this->assertStringContainsString('54dd558aa47b909371b055d5ea8baf38ddcd59229e571ef91c696c5fa47b0c86', $source);
        $this->assertStringContainsString('InitialStopDerivedEvidenceSha256', $source);
        $this->assertStringContainsString('ddc9bced6045445a40c4ee0a4c2c4e04637f33925f4ee0e7f05737b7b8d3d7d4', $source);
        $this->assertStringContainsString('Corrective1', $source);
        $this->assertStringContainsString('LOCAL_ATTEMPT_INITIALIZATION', $source);
        $this->assertStringContainsString('corrective-1', $source);
        $this->assertStringContainsString('EXECUTION_ATTEMPT_ALREADY_RECORDED', $source);
        $this->assertStringContainsString('ssh_attempt_limit=1', $source);
        $this->assertStringContainsString('retry_performed=$false', $source);
        $this->assertStringContainsString('remote_file_mutation=$false', $source);
        $this->assertStringContainsString('persistent_db_write=$false', $source);
        $this->assertStringContainsString('migration_executed=$false', $source);
        $this->assertStringNotContainsString('remote_file_mutation=false', $source);
        $this->assertStringNotContainsString('persistent_db_write=false', $source);
        $this->assertStringNotContainsString('migration_executed=false', $source);
        $this->assertStringContainsString('SQL_EXPECTED_COUNT_MISMATCH', $source);
        $this->assertStringContainsString('sql_statement_limit=24', str_replace(' ', '', $source));
        $this->assertStringContainsString("'none_read_only_g2_preflight_v2'", $source);
        $this->assertStringContainsString("'rise-gate.com/rise-gate-os/.env'", $source);
        $this->assertStringNotContainsString('scp.exe', $source);
        $this->assertStringNotContainsString('RedirectStandardInput', $source);
        $this->assertSame(1, substr_count($source, '$remote = Invoke-Utf8CapturedProcess $preconditions.SshPath (Get-RemoteArguments)'));

        preg_match('/function Get-RemoteArguments \{(?<body>.*?)\n\}/s', $source, $match);
        $remoteArguments = $match['body'] ?? '';
        $this->assertNotSame('', $remoteArguments);
        foreach (['mkdir', 'rm ', 'chmod', 'chown', 'artisan', 'migrate', 'mysql', 'mariadb', 'sh -s', 'bash -s'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $remoteArguments);
        }
    }

    public function test_verify_only_is_local_and_does_not_record_attempt(): void
    {
        $result = $this->runHelper(['-Corrective1', '-VerifyOnly']);
        $result->mustRun();

        $this->assertSame(
            "G2_V2_EXECUTION_HELPER_VERIFY=PASS\nproduction_connection_attempted=false\nproduction_mutation=false\n",
            str_replace("\r\n", "\n", $result->getOutput())
        );
        $this->assertDirectoryDoesNotExist($this->evidenceRoot);
    }

    public function test_initial_stop_evidence_is_exact_and_preserves_zero_mutation_boundary(): void
    {
        $path = base_path('deployment/g2-preflight-v2/evidence/g2-v2-execution-initial-stop.json');
        $this->assertFileExists($path);
        $this->assertSame('54dd558aa47b909371b055d5ea8baf38ddcd59229e571ef91c696c5fa47b0c86', hash_file('sha256', $path));
        $derivedPath = base_path('deployment/g2-preflight-v2/evidence/g2-v2-execution-initial-stop.evidence');
        $this->assertFileExists($derivedPath);
        $this->assertSame('ddc9bced6045445a40c4ee0a4c2c4e04637f33925f4ee0e7f05737b7b8d3d7d4', hash_file('sha256', $derivedPath));
        $evidence = (string) file_get_contents($derivedPath);
        $this->assertStringContainsString('root_cause_code=POWERSHELL_BAREWORD_BOOLEAN_LITERAL', $evidence);
        $this->assertStringContainsString('local_openssh_contract=PASS', $evidence);
        $this->assertStringContainsString('production_connection_attempted=false', $evidence);
        $this->assertStringContainsString('database_connection=not_attempted', $evidence);
        $this->assertStringContainsString('persistent_db_write=false', $evidence);
        $this->assertStringContainsString('ddl=false', $evidence);
        $this->assertStringContainsString('migration_executed=false', $evidence);
        $this->assertStringContainsString('data_mutation=false', $evidence);
    }

    public function test_safe_complete_fixture_is_accepted_without_production_connection(): void
    {
        $fixture = $this->writeFixture($this->passFrames());
        try {
            $result = $this->runHelper(['-Corrective1', '-VerifyOnly', '-EvidenceFixture', $fixture]);
            $result->mustRun();
            $this->assertStringContainsString('G2_V2_EXECUTION_HELPER_VERIFY=PASS', $result->getOutput());
            $this->assertStringContainsString('production_connection_attempted=false', $result->getOutput());
            $this->assertDirectoryDoesNotExist($this->evidenceRoot);
        } finally {
            @unlink($fixture);
        }
    }

    public function test_unsafe_fixture_fails_closed_without_recording_or_connection(): void
    {
        $frames = $this->passFrames();
        $frames[14]['data']['database']['version'] = '/home/raw-path';
        $fixture = $this->writeFixture($frames);
        try {
            $result = $this->runHelper(['-Corrective1', '-VerifyOnly', '-EvidenceFixture', $fixture]);
            $result->run();
            $output = str_replace("\r\n", "\n", $result->getOutput());
            $this->assertFalse($result->isSuccessful());
            $this->assertStringContainsString("G2_V2_READ_ONLY_EXECUTION=STOP\n", $output);
            $this->assertStringContainsString('safe_error_code=EVIDENCE_SANITIZATION_REJECTED', $output);
            $this->assertStringContainsString('production_connection_attempted=false', $output);
            $this->assertDirectoryDoesNotExist($this->evidenceRoot);
        } finally {
            @unlink($fixture);
        }
    }

    public function test_safe_stop_fixture_retains_php_sql_progress_over_following_shell_stop(): void
    {
        $fixture = $this->writeFixture($this->stopFrames());
        try {
            $result = $this->runHelper(['-Corrective1', '-VerifyOnly', '-EvidenceFixture', $fixture]);
            $result->mustRun();
            $output = str_replace("\r\n", "\n", $result->getOutput());
            $this->assertStringContainsString('fixture_outcome=STOP', $output);
            $this->assertStringContainsString('fixture_database_connection=established', $output);
            $this->assertStringContainsString('fixture_sql_statement_count=4', $output);
            $this->assertStringContainsString('fixture_rejected_statement_count=0', $output);
            $this->assertStringContainsString('production_connection_attempted=false', $output);
            $this->assertDirectoryDoesNotExist($this->evidenceRoot);
        } finally {
            @unlink($fixture);
        }
    }

    /** @param list<string> $arguments */
    private function runHelper(array $arguments): Process
    {
        return new Process(array_merge([
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $this->helper,
        ], $arguments), base_path(), timeout: 30);
    }

    /** @param list<array<string, mixed>> $frames */
    private function writeFixture(array $frames): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g2-v2-execution-'.Str::uuid().'.ndjson';
        $lines = array_map(static fn (array $frame): string => json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $frames);
        file_put_contents($path, implode("\n", $lines)."\n");
        return $path;
    }

    /** @return list<array<string, mixed>> */
    private function passFrames(): array
    {
        $frames = [
            $this->frame(1, 'shell', 'shell_started', ['candidate' => self::CANDIDATE]),
            $this->frame(2, 'shell', 'artifact_verified', ['manifest_schema' => 2, 'hashes_verified' => true]),
            $this->frame(3, 'shell', 'php_discovered', ['php_cli' => true]),
            $this->frame(1, 'php', 'contract_started', [
                'candidate' => self::CANDIDATE,
                'application_bootstrap' => 'not_used',
                'database_connection' => 'not_attempted',
            ]),
        ];

        $checks = [
            ['environment_loaded', 'not_attempted', 0],
            ['database_connection', 'established', 0],
            ['database_identity', 'established', 1],
            ['affected_table_metrics', 'established', 2],
            ['organization_users_row_count', 'established', 3],
            ['legacy_role_distribution', 'established', 4],
            ['column_collision_preflight', 'established', 5],
            ['table_collision_preflight', 'established', 6],
            ['active_transaction_snapshot', 'established', 7],
            ['metadata_lock_snapshot', 'established', 8],
        ];
        foreach ($checks as $index => [$name, $connection, $sqlCount]) {
            $frames[] = $this->frame($index + 2, 'database', 'check_completed', [
                'check' => $name,
                'database_connection' => $connection,
                'sql_total_statements' => $sqlCount,
                'sql_rejected_statements' => 0,
            ]);
        }

        $sql = [
            'allowed_statement_classes' => ['SELECT'],
            'statement_counts' => ['SELECT' => 8],
            'total_statements' => 8,
            'rejected_statements' => 0,
            'statement_limit' => 24,
            'persistent_db_write' => false,
            'ddl' => false,
            'migration_execution' => false,
        ];
        $frames[] = $this->frame(12, 'php', 'terminal', [
            'candidate' => self::CANDIDATE,
            'evidence_completeness' => 'complete_for_supported_scope',
            'database' => [
                'engine_family' => 'MariaDB',
                'version' => '10.11.19-MariaDB',
                'character_set' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'affected_table_metrics' => [],
            'organization_users' => [
                'exact_row_count' => 4,
                'legacy_role_counts' => ['owner' => 1, 'admin' => 1, 'member' => 1, 'viewer' => 1, 'other_or_null' => 0],
            ],
            'collision_preflight' => [
                'existing_expected_new_column_count' => 0,
                'existing_expected_new_table_count' => 0,
            ],
            'activity_snapshot' => [
                'active_transactions' => ['status' => 'SUPPORTED', 'active_count' => 0],
                'pending_metadata_locks' => ['status' => 'SUPPORTED', 'pending_count' => 0],
                'snapshot_only' => true,
            ],
            'sql_safety' => $sql,
            'capabilities' => [
                'migration_runtime_prediction' => 'UNSUPPORTED',
                'external_writer_full_visibility' => 'UNSUPPORTED',
            ],
            'production_change_scope' => 'none_read_only_g2_preflight_v2',
            'secret_output' => false,
            'raw_identifier_output' => false,
            'raw_exception_output' => false,
        ]);
        $frames[] = $this->frame(4, 'shell', 'shell_terminal', [
            'php_exit_code' => 0,
            'production_change_scope' => 'none_read_only_g2_preflight_v2',
        ]);

        return $frames;
    }

    /** @return list<array<string, mixed>> */
    private function stopFrames(): array
    {
        $frames = array_slice($this->passFrames(), 0, 10);
        $completed = [
            'environment_loaded','database_connection','database_identity','affected_table_metrics',
            'organization_users_row_count','legacy_role_distribution',
        ];
        $sql = [
            'allowed_statement_classes' => ['SELECT'],
            'statement_counts' => ['SELECT' => 4],
            'total_statements' => 4,
            'rejected_statements' => 0,
            'statement_limit' => 24,
            'persistent_db_write' => false,
            'ddl' => false,
            'migration_execution' => false,
        ];
        $frames[] = $this->frame(8, 'php', 'terminal', [
            'safe_error_code' => 'G2_V2_READ_ONLY_PREFLIGHT_FAILED',
            'failure_stage' => 'database_read_only_preflight',
            'evidence_completeness' => 'partial',
            'database_connection' => 'established',
            'last_completed_check' => 'legacy_role_distribution',
            'completed_checks' => $completed,
            'sql_safety' => $sql,
            'production_change_scope' => 'none_read_only_g2_preflight_v2',
            'secret_output' => false,
            'raw_identifier_output' => false,
            'raw_exception_output' => false,
        ], 'STOP');
        $frames[] = $this->frame(4, 'shell', 'terminal', [
            'safe_error_code' => 'G2_V2_PHP_AUDITOR_STOPPED',
            'failure_stage' => 'php_execution',
            'production_change_scope' => 'none_read_only_g2_preflight_v2',
            'secret_output' => false,
        ], 'STOP');
        return $frames;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function frame(int $sequence, string $layer, string $event, array $data, string $status = 'PASS'): array
    {
        return [
            'schema_version' => 2,
            'sequence' => $sequence,
            'layer' => $layer,
            'event' => $event,
            'status' => $status,
            'data' => $data,
        ];
    }
}
