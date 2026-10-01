<?php

namespace Tests\Feature;

use Tests\TestCase;

class Ir1G2MigrationSafetyTest extends TestCase
{
    private const MIGRATION_HASHES = [
        '2026_09_19_000001_add_scope_one_contract_to_ai_proposals.php' => '8aa1eb87ec93b4de31afbc6c153f972ca27cce48298c3c2da6bc176a38b2df28',
        '2026_09_19_000001_add_scope_two_account_security.php' => '8238c4b5a5dfebf8b57294a089e791a795514062af433d3598ebf7c906e2965f',
        '2026_09_20_000002_add_scope_three_organization_foundation.php' => '29f1781fa2d9b507aef461ce0bfd28997256bd35efae3a34ba34a745fd568136',
        '2026_09_20_000003_add_scope_four_staff_invitation.php' => '38fb9bfc3a1268ab3fae82679762be13919c11b0f1ba1bf6fbe99797a8d0606f',
        '2026_09_20_000004_add_scope_five_membership_lifecycle.php' => 'c7cec538ba3e897734add1783303186c8cf036e5471e2a1ea3a2b815901edb69',
        '2026_09_20_000005_add_scope_six_owner_onboarding.php' => '103cdb5290d5d55edfde06c8f4050e4505f1589c24978b223b9cffbc5d0e4474',
        '2026_09_21_000006_create_scope_seven_business_domains.php' => '03f2556d745e19ffbc26787999746246457b50257e6b2d0500f221a4c6486b61',
        '2026_09_21_000007_add_direction_and_display_order_to_business_domains.php' => 'a914c5cedf2bc706e59c3e7450cdf03555efd83380a6157529d66102924c2870',
        '2026_09_21_000008_add_product_organization_eligibility.php' => '550e0f96aae7e5154e69ed1bc66e5087321434f348e4fab9e028a2d479bd50d4',
        '2026_09_24_000001_add_scope_eight_project_action_foundation.php' => '127757be10057468be09dce6e8ebdf3180e31f65c7d62da550c02d9464dd0671',
        '2026_09_25_000001_add_scope_nine_action_execution_foundation.php' => '61504e676ef018d8b92ba37b907bf59adc10854f907ac2c988801c01e97590c6',
    ];

    public function test_exact_pending_migration_set_is_hash_bound(): void
    {
        $this->assertCount(11, self::MIGRATION_HASHES);

        foreach (self::MIGRATION_HASHES as $file => $expectedHash) {
            $path = database_path('migrations/'.$file);
            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $file);
        }
    }

    public function test_data_mutation_and_rollback_characteristics_are_explicit(): void
    {
        $sources = [];
        foreach (array_keys(self::MIGRATION_HASHES) as $file) {
            $sources[$file] = (string) file_get_contents(database_path('migrations/'.$file));
        }
        $combined = implode("\n", $sources);

        $this->assertSame(4, substr_count($combined, '->update('));
        $this->assertSame(0, substr_count($combined, '->delete('));
        $this->assertStringContainsString("->whereIn('role', ['member', 'viewer'])", $combined);
        $this->assertStringContainsString("->update(['membership_status' => 'active'])", $combined);

        $eligibility = $sources['2026_09_21_000008_add_product_organization_eligibility.php'];
        $this->assertSame(3, substr_count($eligibility, 'DB::statement('));
        $this->assertMatchesRegularExpression('/public function down\(\): void\s*\{\s*\/\/ Product admission rollback.+intentionally retained/s', $eligibility);
    }

    public function test_g2_production_preflight_is_select_only_and_sanitized(): void
    {
        $path = base_path('deployment/r0-audit/g2-migration-preflight.php');
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach ([
            "const G2_SQL_LIMIT = 24",
            "preg_match('/^\\s*SELECT\\b/i'",
            "'persistent_db_write' => false",
            "'ddl' => false",
            "'migration_execution' => false",
            "'secret_output' => false",
            "'raw_identifier_output' => false",
            "'raw_exception_output' => false",
            "'partial_evidence' => \$g2Progress",
            "'database_connection' => 'not_attempted'",
            "function g2Checkpoint(string \$condition)",
            "external_writer_full_visibility' => 'UNSUPPORTED",
        ] as $required) {
            $this->assertStringContainsString($required, $source);
        }

        $this->assertStringNotContainsString("bootstrap/app.php", $source);
        $this->assertStringNotContainsString('handleCommand(', $source);
        $this->assertStringNotContainsString('->exec(', $source);
        $this->assertStringNotContainsString('->prepare(', $source);
    }

    public function test_one_command_helper_is_fail_closed_and_has_no_remote_mutation(): void
    {
        $path = base_path('deployment/r0-audit/Invoke-G2MigrationSafetyPreflight.ps1');
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach ([
            '924af91188cc60d33ff87c91b94ecc1d539566e6',
            'ffd48f4e508b299e24921fb4a016b12f97950043af63abc80d471080ac4bf038',
            '15e20d272f39d1bd69290af6b5c2f681b52bb42a09273b783ca77ad7eacf4340',
            '76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c',
            '918cec809034fc752906ed9e140da10c2255e36009e76765b624d6d2c8cd8808',
            '81795b54e7419f8fb29e0d5de306d6768b76ab27da69badab7d6fcf3f2c69a55',
            'd2223bc7edb8db419fe275e086b0ba1589090e7873c8b279b1166dc75f871068',
            'G2_ORIGINAL_ATTEMPT_CONTRACT_MISMATCH',
            'G2_CORRECTIVE1_ATTEMPT_CONTRACT_MISMATCH',
            'G2_CORRECTIVE2_ATTEMPT_CONTRACT_MISMATCH',
            'production-g2-migration-preflight-corrective-1-',
            'production-g2-migration-preflight-corrective-2-',
            'production-g2-migration-preflight-corrective-3-',
            'G2_REMOTE_REPORTED_STOP',
            'G2_SAFE_PASS_WITH_STDERR',
            'Test-SafeFailureEvidence',
            'Assert-EvidenceValidatorRegression',
            'evidence_validator_regression=PASS',
            'local_exception_sha256',
            'stdout_sha256',
            'remote_last_completed_condition',
            'remote_sql_total_statements',
            'BatchMode=yes',
            'StrictHostKeyChecking=yes',
            'NumberOfPasswordPrompts=0',
            'ConnectionAttempts=1',
            'STEP_RETRY_FORBIDDEN',
            'production_mutation = $false',
            'retry_performed = $false',
            'G2_MIGRATION_SAFETY_PREFLIGHT=PASS',
            'G2_MIGRATION_SAFETY_PREFLIGHT=STOP',
        ] as $required) {
            $this->assertStringContainsString($required, $source);
        }

        foreach (['New-Item -ItemType Directory -Path $evidenceRoot', '[IO.File]::WriteAllText'] as $localEvidenceWrite) {
            $this->assertStringContainsString($localEvidenceWrite, $source);
        }
        $this->assertStringNotContainsString("scp.exe @", $source);
        $this->assertStringNotContainsString("sftp.exe @", $source);
        $this->assertStringNotContainsString("base64 -d", $source);
        $this->assertStringContainsString("<<'__G2_PHP_SOURCE_924AF911__'", $source);
        $this->assertLessThan(
            strpos($source, 'if (-not [string]::IsNullOrEmpty($result.Stderr))'),
            strpos($source, '$safePass = Test-SafePassEvidence'),
        );
    }
}
