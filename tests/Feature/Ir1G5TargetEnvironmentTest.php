<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class Ir1G5TargetEnvironmentTest extends TestCase
{
    private const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    public function test_contract_separates_legacy_service_site_and_application_target(): void
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('deployment/g5-target-environment/target-contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('company-os.ir1.g5-target-environment.v1', $contract['contract_id']);
        $this->assertSame(self::CANDIDATE, $contract['source_commit']);
        $this->assertSame('app.company-os.jp', $contract['production_urls']['application_target']);
        $this->assertSame('os.rise-gate.com', $contract['production_urls']['legacy_application']);
        $this->assertSame('company-os.jp/company-os-app', $contract['relative_paths']['target_topology_root']);
        $this->assertSame('company-os.jp/public_html/app.company-os.jp', $contract['relative_paths']['target_public_entry']);
        $this->assertSame('company-os.jp/company-os-app/current/public', $contract['relative_paths']['target_public_entry_link']);
        $this->assertSame('rise-gate.com/', $contract['path_boundaries']['legacy_prefix_read_only']);
        $this->assertSame('company-os.jp/', $contract['path_boundaries']['target_mutation_prefix']);
        $this->assertFalse($contract['production_mutation_authorized']);
        $this->assertCount(9, $contract['continuing_blockers']);
    }

    public function test_read_only_inspector_has_no_remote_mutation_or_secret_read(): void
    {
        $script = (string) file_get_contents(base_path('deployment/g5-target-environment/inspect-target-read-only.sh'));

        foreach (['path_state', 'entry_count', 'domain_public_same_device', 'requires_mutating_rehearsal',
            'none_read_only_target_inspection', 'app.company-os.jp'] as $required) {
            $this->assertStringContainsString($required, $script);
        }
        foreach (['mkdir -', 'rm -', 'ln -s', 'mv -', 'chmod ', 'chown ', 'cat "$HOME', 'source "$HOME'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
    }

    public function test_rehearsal_is_isolated_cleanup_bound_and_database_free(): void
    {
        $script = (string) file_get_contents(base_path('deployment/g5-target-environment/rehearse-posix-capabilities.sh'));

        $this->assertStringContainsString('.ir1-g5-capability-'.self::CANDIDATE, $script);
        $this->assertStringContainsString('TARGET_PATHS_DIFFERENT_FILESYSTEM', $script);
        $this->assertStringContainsString('mv -Tf', $script);
        $this->assertStringContainsString('chmod 0600', $script);
        $this->assertStringContainsString('cleanup_state=complete', $script);
        $this->assertStringContainsString('residual_entry_count=0', $script);
        $this->assertStringContainsString('legacy_production_changed=false', $script);
        $this->assertStringNotContainsString('rise-gate.com', $script);
        $this->assertStringNotContainsString('mysql', $script);
        $this->assertStringNotContainsString('artisan', $script);
    }

    public function test_boundary_failure_simulation_preserves_legacy_and_public_entry(): void
    {
        $process = new Process([PHP_BINARY, base_path('deployment/g5-target-environment/simulate-g5-boundaries.php')], base_path());
        $process->mustRun();

        $this->assertStringContainsString('G5_BOUNDARY_SIMULATION=PASS', $process->getOutput());
        $this->assertStringContainsString('scenario_count=7', $process->getOutput());
        $this->assertStringContainsString('assertion_count=15', $process->getOutput());
        $this->assertStringContainsString('legacy_mutation=0', $process->getOutput());
        $this->assertStringContainsString('production_connection=false', $process->getOutput());
    }

    public function test_helper_is_one_command_one_shot_and_package_bound(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5TargetEnvironment.ps1'));

        foreach ([
            'f89a71cbfe453b2e9bd74d20fdf5e3bab9c2b98e28775ddf755922713ca8a232',
            "ValidateSet('Inspect','Rehearse')",
            'STEP_RETRY_FORBIDDEN',
            'READ_ONLY_INSPECTION_REQUIRED',
            'CAPABILITY_REHEARSAL_NOT_ELIGIBLE',
            'StrictHostKeyChecking=yes',
            'production_connection_attempted=false',
            'production_mutation=false',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }
        $this->assertStringNotContainsString('scp.exe', $helper);
        $this->assertStringNotContainsString('Invoke-WebRequest', $helper);
    }

    public function test_helper_uses_powershell_51_safe_line_ending_normalization_on_the_human_path(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g5-target-environment/Invoke-G5TargetEnvironment.ps1'));

        $this->assertStringContainsString('g5-discovery-corrective-1', $helper);
        $this->assertStringContainsString('function Read-LfScript', $helper);
        $this->assertStringContainsString('$text.Replace($crlf,$lf).Replace($cr,$lf)', $helper);
        $this->assertStringContainsString('human_execution_path=validated_through_remote_script_preparation', $helper);
        $this->assertStringContainsString("return 'UNEXPECTED_LOCAL_FAILURE'", $helper);
        $this->assertStringContainsString('safe_error_code=$(Get-SafeErrorCode $_.Exception)', $helper);
        $this->assertStringNotContainsString('.Replace(([char]13+[char]10),[char]10)', $helper);
        $this->assertStringNotContainsString('safe_error_code=$($_.Exception.Message)', $helper);

        $preparation = strpos($helper, '$scriptText=Read-LfScript');
        $verifyExit = strpos($helper, 'if($VerifyOnly)');
        $productionStage = strpos($helper, '$FailureStage=if($Step-eq\'Inspect\')');
        $connectionAttempt = strpos($helper, '$ConnectionAttempted=$true');

        $this->assertIsInt($preparation);
        $this->assertIsInt($verifyExit);
        $this->assertIsInt($productionStage);
        $this->assertIsInt($connectionAttempt);
        $this->assertLessThan($verifyExit, $preparation);
        $this->assertLessThan($connectionAttempt, $productionStage);
    }

    public function test_builder_is_g4_bound_deterministic_and_production_free(): void
    {
        $builder = (string) file_get_contents(base_path('deployment/g5-target-environment/Build-G5TargetPackage.ps1'));

        $this->assertStringContainsString('G4_PACKAGE_BINDING_MISMATCH', $builder);
        $this->assertStringContainsString('DETERMINISTIC_BUILD_MISMATCH', $builder);
        $this->assertStringContainsString('G5_TARGET_PACKAGE_VERIFY_ONLY=PASS', $builder);
        $this->assertStringContainsString('connection_attempted=$false', $builder);
        $this->assertStringContainsString('mutation=$false', $builder);
        $this->assertStringNotContainsString('ssh ', $builder);
        $this->assertStringNotContainsString('scp ', $builder);
    }
}
