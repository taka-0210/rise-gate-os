<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PostmarkCredentialIntakeReadinessTest extends TestCase
{
    public function test_every_required_check_fails_closed_for_missing_false_and_untrusted_values(): void
    {
        $root = dirname(__DIR__, 2);
        $contract = json_decode(file_get_contents($root.'/deployment/g5-target-environment/postmark-credential-intake-readiness-contract.json'), true, 512, JSON_THROW_ON_ERROR);
        $simulate = require $root.'/deployment/g5-target-environment/simulate-postmark-intake-readiness.php';
        foreach (['required_intake_checks', 'required_target_checks'] as $phase) {
            $checks = $contract[$phase];
            $passing = array_fill_keys($checks, true);
            $this->assertSame('SIMULATION_PASS', $simulate($checks, $passing)['result']);
            $this->assertFalse($simulate($checks, $passing)['publish_allowed']);
            foreach ($checks as $check) {
                foreach ([false, null, 'true', 1] as $invalid) {
                    $observations = $passing;
                    $observations[$check] = $invalid;
                    $this->assertSame(['result' => 'STOP', 'safe_error_code' => 'READINESS_CHECK_FAILED', 'publish_allowed' => false], $simulate($checks, $observations));
                }
                $observations = $passing;
                unset($observations[$check]);
                $this->assertSame('STOP', $simulate($checks, $observations)['result']);
            }
        }
        foreach (['production_connection_authorized', 'token_access_authorized', 'shared_state_authorized', 'deploy_authorized', 'live_helper_implemented'] as $flag) {
            $this->assertFalse($contract[$flag]);
        }
        $this->assertSame(21093973, $contract['server_id']);
        $this->assertSame('0600', $contract['target_mode']);
        $this->assertSame('UNKNOWN', $contract['account_email_verification']);
        $this->assertSame(1, $contract['provider_read']['attempts']);
        $this->assertFalse($contract['provider_read']['redirects']);
        $this->assertTrue($contract['provider_read']['tls_verification']);
    }
}
