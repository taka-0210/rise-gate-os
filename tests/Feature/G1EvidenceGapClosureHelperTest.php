<?php

namespace Tests\Feature;

use Tests\TestCase;

class G1EvidenceGapClosureHelperTest extends TestCase
{
    public function test_g1_helper_is_bound_to_completed_r0_and_fail_closed(): void
    {
        $path = base_path('deployment/r0-audit/Invoke-G1EvidenceGapClosure.ps1');
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        foreach ([
            '924af91188cc60d33ff87c91b94ecc1d539566e6',
            '159392dde20356febf20ea744953c7b901696e5c0f7dc5f45d1e2ae914f13b18',
            '4cb2f91d7d12e1083e8edaee41a3a408d0b2577d46fa1c69ad7a978a83c84be9',
            '03f0a69dbeac35747082f06c34e2b492f03cd0bcc8ebd18dc251c81372d0a7ac',
            '0c32ec4743201ba721cb93d74a6b1b479d6e53369f17ddc47575cc115af418cf',
            'BatchMode=yes',
            'StrictHostKeyChecking=yes',
            'NumberOfPasswordPrompts=0',
            'ConnectionAttempts=1',
            'ClearAllForwardings=yes',
            'ForwardAgent=no',
            'PermitLocalCommand=no',
            'STEP_RETRY_FORBIDDEN',
            'production_change_scope=none_read_only_g1_inspection',
            'raw_stdout_stored = $false',
            'raw_stderr_stored = $false',
            'retry_performed = $false',
            'production_mutation = $false',
            'VerifyOnly',
            'VerifyLocalPreconditionsOnly',
            'RETURN_TO_HUMAN_CHATGPT',
        ] as $required) {
            $this->assertStringContainsString($required, $source);
        }
    }

    public function test_remote_inspection_has_no_mutating_or_secret_read_operations(): void
    {
        $source = file_get_contents(base_path('deployment/r0-audit/Invoke-G1EvidenceGapClosure.ps1'));
        $this->assertIsString($source);

        foreach ([
            'mkdir ', 'touch ', 'chmod ', 'chown ', 'rm -', 'mv ', 'cp ', 'scp.exe',
            'sftp ', 'migrate --force', 'git push', 'ln -s', 'tar -x',
            'cat "$APP/.env"', 'source "$APP/.env"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $this->assertStringContainsString('stat -c', $source);
        $this->assertStringContainsString('crontab -l', $source);
        $this->assertStringContainsString('sha256sum', $source);
        $this->assertStringContainsString('git -C "$APP" rev-parse --verify HEAD', $source);
        $this->assertStringContainsString('backup_db_candidate_count', $source);
        $this->assertStringContainsString('env_mode', $source);
        $this->assertStringContainsString('secret_output=false', $source);
    }

    public function test_g1_documented_scope_does_not_claim_restore_or_deploy(): void
    {
        $path = base_path('deployment/r0-audit/G1_EVIDENCE_GAP_PROCEDURE.md');
        $this->assertFileExists($path);
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringContainsString('restore readiness', $source);
        $this->assertStringContainsString('UNKNOWN', $source);
        $this->assertStringContainsString('Production mutation = 0', $source);
        $this->assertStringContainsString('1 Step = 1 Command', $source);
        $this->assertStringContainsString('G2 Migration Safety', $source);
    }
}
