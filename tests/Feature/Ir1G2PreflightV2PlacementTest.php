<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class Ir1G2PreflightV2PlacementTest extends TestCase
{
    private const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    public function test_placement_helper_is_exact_fail_closed_and_has_no_execution_scope(): void
    {
        $source = (string) file_get_contents(base_path('deployment/g2-preflight-v2/Invoke-G2PreflightV2Placement.ps1'));
        foreach ([
            self::CANDIDATE,
            'b055585e09d7ae00c65bcaacad213fc4c92d260d4f8e2125884fe72d3cfb6b99',
            'f0abf01a18ae1e2bd0b2fb79197eb42c8570e62794da570453f30e603cb89dfc',
            '159392dde20356febf20ea744953c7b901696e5c0f7dc5f45d1e2ae914f13b18',
            'ebebba036e01ae72333f4d446ae337d1ff89721ceac794a6bed435f4795edc86',
            'PLACEMENT_ATTEMPT_ALREADY_RECORDED',
            'test ! -e "$TARGET"',
            'test ! -e "$PACKAGE"',
            'sha256sum --check --strict checksums.sha256',
            'retry_performed=$false',
            'overwrite_performed=$false',
            'db_connection_attempted=$false',
            'sql_executed=$false',
            'migration_executed=$false',
            'G2_V2_PLACEMENT=PASS',
            'G2_V2_PLACEMENT=STOP',
        ] as $required) {
            $this->assertStringContainsString($required, $source);
        }
        $remoteScripts = implode(PHP_EOL, $this->remoteScripts($source));
        foreach (['artisan ', 'migrate', 'DB_PASSWORD', 'chmod ', 'chown ', 'sudo '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $remoteScripts);
        }
    }

    public function test_remote_placement_scripts_complete_against_exact_local_artifacts(): void
    {
        if (! is_file('C:\\Program Files\\Git\\bin\\bash.exe')) {
            $this->markTestSkipped('Git Bash unavailable.');
        }
        $root = sys_get_temp_dir().'/company-os-g2-v2-placement-'.bin2hex(random_bytes(5));
        $candidate = $root.'/.ir1-r0-audit/'.self::CANDIDATE;
        mkdir($candidate.'/bundle', 0700, true);
        $r0Name = 'ir1-r0-audit-bundle-'.self::CANDIDATE.'.tar.gz';
        copy(storage_path('app/release-audit/'.$r0Name), $candidate.'/'.$r0Name);
        $this->bash([
            '-c', 'tar --force-local -xzf $1 -C $2', 'extract',
            str_replace('\\', '/', $candidate.'/'.$r0Name), str_replace('\\', '/', $candidate.'/bundle'),
        ])->mustRun();

        $source = (string) file_get_contents(base_path('deployment/g2-preflight-v2/Invoke-G2PreflightV2Placement.ps1'));
        [$precheck, $finalize] = $this->remoteScripts($source);
        $precheckPath = $root.'/precheck.sh';
        $finalizePath = $root.'/finalize.sh';
        file_put_contents($precheckPath, $this->portableFixtureScript($precheck));
        file_put_contents($finalizePath, $this->portableFixtureScript($finalize));

        $prepare = $this->runRemoteScript($root, $precheckPath);
        $prepare->mustRun();
        $this->assertSame("G2_V2_PLACEMENT_PREPARE=PASS\noverwrite_performed=false\n", str_replace("\r\n", "\n", $prepare->getOutput()));

        $target = $candidate.'/g2-preflight-v2';
        $artifactName = 'ir1-g2-preflight-v2-'.self::CANDIDATE.'.tar.gz';
        copy(storage_path('app/release-audit/'.$artifactName), $target.'/'.$artifactName);
        $finish = $this->runRemoteScript($root, $finalizePath);
        $finish->mustRun();
        $this->assertStringContainsString('artifact_sha256_verified=true', $finish->getOutput());
        $this->assertStringContainsString('manifest_sha256_verified=true', $finish->getOutput());
        $this->assertFileExists($target.'/package/launcher.sh');
        $this->assertFileExists($target.'/package/auditor.php');

        $repeat = $this->runRemoteScript($root, $precheckPath);
        $repeat->run();
        $this->assertNotSame(0, $repeat->getExitCode());
        $this->assertFileExists($target.'/'.$artifactName);
        $this->removeTree($root);
    }

    public function test_verify_only_is_local_or_completed_placement_receipt_remains_immutable(): void
    {
        $state = storage_path('app/release-audit/production-g2-preflight-v2-placement-'.self::CANDIDATE);
        if (is_dir($state)) {
            $receiptPath = $state.'/execution-state.json';
            $this->assertFileExists($receiptPath);
            $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(self::CANDIDATE, $receipt['candidate']);
            $this->assertSame('PASS', $receipt['status']);
            $this->assertSame(0, $receipt['remote_exit_code']);
            $this->assertFalse($receipt['overwrite_performed']);
            $this->assertFalse($receipt['db_connection_attempted']);
            $this->assertFalse($receipt['sql_executed']);
            $this->assertFalse($receipt['migration_executed']);
            $this->assertFalse($receipt['secret_output']);
            return;
        }
        $this->assertDirectoryDoesNotExist($state);
        $process = new Process([
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            base_path('deployment/g2-preflight-v2/Invoke-G2PreflightV2Placement.ps1'), '-VerifyOnly',
        ], base_path());
        $process->mustRun();
        $this->assertStringContainsString('G2_V2_PLACEMENT_HELPER_VERIFY=PASS', $process->getOutput());
        $this->assertStringContainsString('production_connection_attempted=false', $process->getOutput());
        $this->assertDirectoryDoesNotExist($state);
    }

    /** @return array{0:string,1:string} */
    private function remoteScripts(string $source): array
    {
        preg_match("/function Get-PrecheckScript \\{.*?return \\(@'\\R(?<body>.*?)\\R'@ -replace/s", $source, $precheck);
        preg_match("/function Get-FinalizeScript \\{.*?return \\(@'\\R(?<body>.*?)\\R'@ -replace/s", $source, $finalize);
        $this->assertArrayHasKey('body', $precheck);
        $this->assertArrayHasKey('body', $finalize);
        return [str_replace("\r\n", "\n", $precheck['body'])."\n", str_replace("\r\n", "\n", $finalize['body'])."\n"];
    }

    private function runRemoteScript(string $home, string $script): Process
    {
        return $this->bash(['-c', 'HOME="$1" sh "$2"', 'placement', str_replace('\\', '/', $home), str_replace('\\', '/', $script)]);
    }

    private function portableFixtureScript(string $script): string
    {
        $guard = 'case '.chr(34).'$ACTUAL_HOME'.chr(34).' in /home/[A-Za-z0-9._-]*) ;; *) exit 41 ;; esac';
        $portable = str_replace($guard, 'test -n '.chr(34).'$ACTUAL_HOME'.chr(34), $script);
        $this->assertNotSame($script, $portable, 'Production /home guard must remain present before fixture adaptation.');
        return $portable;
    }

    /** @param list<string> $arguments */
    private function bash(array $arguments): Process
    {
        return new Process(array_merge(['C:\\Program Files\\Git\\bin\\bash.exe'], $arguments), base_path());
    }

    private function removeTree(string $root): void
    {
        $resolved = realpath($root);
        $prefix = str_replace('\\', '/', sys_get_temp_dir()).'/company-os-g2-v2-placement-';
        if ($resolved === false || ! str_starts_with(str_replace('\\', '/', $resolved), $prefix)) {
            $this->fail('Unsafe placement fixture cleanup target.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($resolved);
    }
}
