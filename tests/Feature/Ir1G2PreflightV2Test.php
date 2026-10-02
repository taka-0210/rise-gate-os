<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class Ir1G2PreflightV2Test extends TestCase
{
    private const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    public function test_v2_sources_remove_inline_transport_and_preserve_read_only_contract(): void
    {
        $launcher = (string) file_get_contents(base_path('deployment/g2-preflight-v2/launcher.sh'));
        $auditor = (string) file_get_contents(base_path('deployment/g2-preflight-v2/auditor.php'));

        $this->assertStringNotContainsString("\r", $launcher);
        $this->assertStringNotContainsString('base64', $launcher);
        $this->assertStringNotContainsString('<<', $launcher);
        $this->assertStringNotContainsString('bash -s', $launcher);
        $this->assertStringContainsString('sha256sum -c checksums.sha256', $launcher);
        $this->assertStringContainsString('artifact_verified', $launcher);
        $this->assertStringContainsString('php_discovered', $launcher);
        $this->assertStringContainsString('2>/dev/null', $launcher);

        foreach ([
            "const G2_V2_SQL_LIMIT = 24",
            "preg_match('/^\\s*SELECT\\b/i'",
            "'persistent_db_write' => false",
            "'ddl' => false",
            "'migration_execution' => false",
            "'application_bootstrap' => 'not_used'",
            "'contract_started'",
            "'check_completed'",
            "'database_connection' => 'not_attempted'",
            "'secret_output' => false",
            "'raw_identifier_output' => false",
            "'raw_exception_output' => false",
        ] as $required) {
            $this->assertStringContainsString($required, $auditor);
        }
        $this->assertStringNotContainsString('bootstrap/app.php', $auditor);
        $this->assertStringNotContainsString('->exec(', $auditor);
        $this->assertStringNotContainsString('->prepare(', $auditor);
    }

    public function test_exact_artifact_is_candidate_bound_and_hash_complete(): void
    {
        $artifact = storage_path('app/release-audit/ir1-g2-preflight-v2-'.self::CANDIDATE.'.tar.gz');
        $manifestPath = $artifact.'.manifest.json';
        $checksumsPath = $artifact.'.checksums.sha256';
        $shaPath = $artifact.'.sha256';
        $this->assertFileExists($artifact);
        $this->assertFileExists($manifestPath);
        $this->assertFileExists($checksumsPath);
        $this->assertFileExists($shaPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $manifest['schema_version']);
        $this->assertSame(self::CANDIDATE, $manifest['source_commit']);
        $this->assertSame('a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d', $manifest['r0_bundle_sha256']);
        $this->assertSame(['auditor.php', 'launcher.sh'], $manifest['expected_file_set']);
        $this->assertSame(['SELECT'], $manifest['execution_contract']['database_statement_classes']);
        $this->assertFalse($manifest['execution_contract']['persistent_db_write']);
        $this->assertSame(['ARTIFACT_REVIEW', 'PRODUCTION_PLACEMENT', 'READ_ONLY_EXECUTION'], $manifest['gate_sequence']);

        $declaredArchiveHash = strtolower(strtok(trim((string) file_get_contents($shaPath)), " \t"));
        $this->assertSame(hash_file('sha256', $artifact), $declaredArchiveHash);
        $this->assertSame(3, count(array_filter(explode("\n", trim((string) file_get_contents($checksumsPath))))));
    }

    public function test_posix_launcher_reports_incremental_safe_failure_for_artifact_tamper(): void
    {
        $bash = 'C:\\Program Files\\Git\\bin\\bash.exe';
        if (! is_file($bash)) {
            $this->markTestSkipped('Git Bash unavailable.');
        }
        $stage = $this->extractArtifact();
        file_put_contents($stage.'/auditor.php', "\n", FILE_APPEND);
        $process = new Process([$bash, $stage.'/launcher.sh', $stage, $stage.'/.env']);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getErrorOutput());
        $frames = $this->decodeFrames($process->getOutput());
        $this->assertSame('shell_started', $frames[0]['event']);
        $this->assertSame('G2_V2_ARTIFACT_HASH_MISMATCH', $frames[1]['data']['safe_error_code']);
        $this->assertStringNotContainsString($stage, $process->getOutput());
        $this->removeStage($stage);
    }

    public function test_php_contract_emits_started_frame_before_identity_stop(): void
    {
        $php = PHP_BINARY;
        $auditor = base_path('deployment/g2-preflight-v2/auditor.php');
        $process = new Process([$php, $auditor], base_path(), ['G2_V2_CANDIDATE' => 'wrong']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $frames = $this->decodeFrames($process->getOutput());
        $this->assertSame('contract_started', $frames[0]['event']);
        $this->assertSame('STOP', $frames[1]['status']);
        $this->assertSame('not_attempted', $frames[1]['data']['database_connection']);
        $this->assertSame(0, $frames[1]['data']['sql_safety']['total_statements']);
        $this->assertFalse($frames[1]['data']['sql_safety']['persistent_db_write']);
    }

    public function test_posix_launcher_reports_php_discovery_failure_without_raw_stderr(): void
    {
        $stage = $this->extractArtifact();
        $process = new Process(
            ['C:\\Program Files\\Git\\bin\\bash.exe', $stage.'/launcher.sh', $stage, $stage.'/.env'],
            null,
            ['G2_V2_PHP_BIN' => 'g2-v2-missing-php'],
        );
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getErrorOutput());
        $frames = $this->decodeFrames($process->getOutput());
        $this->assertSame(['shell_started', 'artifact_verified', 'terminal'], array_column($frames, 'event'));
        $this->assertSame('G2_V2_PHP_UNAVAILABLE', $frames[2]['data']['safe_error_code']);
        $this->removeStage($stage);
    }

    public function test_database_failure_retains_incremental_contract_and_zero_sql(): void
    {
        $stage = $this->extractArtifact();
        $environment = $stage.'/.isolated-failure.env';
        file_put_contents($environment, implode(PHP_EOL, [
            'DB_CONNECTION=mysql', 'DB_HOST=127.0.0.1', 'DB_PORT=1',
            'DB_DATABASE=unavailable', 'DB_USERNAME=none', 'DB_PASSWORD=none', '',
        ]));
        $process = new Process(
            ['C:\\Program Files\\Git\\bin\\bash.exe', $stage.'/launcher.sh', str_replace('\\', '/', base_path()), $environment],
            null,
            ['G2_V2_PHP_BIN' => str_replace('\\', '/', PHP_BINARY)],
        );
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getErrorOutput());
        $frames = $this->decodeFrames($process->getOutput());
        $phpStart = collect($frames)->firstWhere('event', 'contract_started');
        $phpStop = collect($frames)->first(fn (array $frame): bool => $frame['layer'] === 'php' && $frame['event'] === 'terminal');
        $this->assertSame('PASS', $phpStart['status']);
        $this->assertSame('STOP', $phpStop['status']);
        $this->assertSame('not_attempted', $phpStop['data']['database_connection']);
        $this->assertSame('environment_loaded', $phpStop['data']['last_completed_check']);
        $this->assertSame(0, $phpStop['data']['sql_safety']['total_statements']);
        $this->assertSame(0, $phpStop['data']['sql_safety']['rejected_statements']);
        $this->removeStage($stage);
    }

    public function test_prior_harness_failure_modes_are_explicit_regression_boundaries(): void
    {
        $launcher = (string) file_get_contents(base_path('deployment/g2-preflight-v2/launcher.sh'));
        $manifestBuilder = (string) file_get_contents(base_path('deployment/g2-preflight-v2/build-manifest.php'));
        $boundaries = [
            'corrective_1_external_base64' => ! str_contains($launcher, 'base64'),
            'corrective_2_stdout_discard' => str_contains($launcher, '"$php_bin" "$script_dir/auditor.php"'),
            'corrective_3_empty_terminal_only_output' => str_contains($launcher, 'shell_started') && str_contains($launcher, 'artifact_verified'),
            'corrective_4_crlf_heredoc' => ! str_contains($launcher, "\r") && ! str_contains($launcher, '<<'),
            'corrective_5_native_argument_tokenization' => ! str_contains($launcher, 'ssh') && ! str_contains($launcher, 'bash -s'),
            'exact_artifact_manifest' => str_contains($manifestBuilder, "'source_commit' => CANDIDATE"),
        ];
        foreach ($boundaries as $boundary => $passed) {
            $this->assertTrue($passed, $boundary);
        }
    }

    private function extractArtifact(): string
    {
        $artifact = storage_path('app/release-audit/ir1-g2-preflight-v2-'.self::CANDIDATE.'.tar.gz');
        $stage = sys_get_temp_dir().'/company-os-g2-v2-test-'.bin2hex(random_bytes(5));
        mkdir($stage.'/payload', 0700, true);
        $process = new Process(['C:\\Program Files\\Git\\bin\\bash.exe', '-c', 'tar --force-local -xzf $1 -C $2', 'extract', str_replace('\\', '/', $artifact), str_replace('\\', '/', $stage.'/payload')]);
        $process->mustRun();
        return $stage.'/payload';
    }

    /** @return list<array<string, mixed>> */
    private function decodeFrames(string $output): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($output)))),
        );
    }

    private function removeStage(string $payload): void
    {
        $root = dirname($payload);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
