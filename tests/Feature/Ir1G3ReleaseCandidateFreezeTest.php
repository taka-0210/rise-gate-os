<?php

namespace Tests\Feature;

use Tests\TestCase;

class Ir1G3ReleaseCandidateFreezeTest extends TestCase
{
    private const CANDIDATE = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    public function test_schema_addition_manifest_is_exact_and_records_the_corrected_column_count(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('deployment/g3-rc/ir1-schema-additions.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::CANDIDATE, $manifest['source_commit']);
        $this->assertSame(31, $manifest['new_table_count']);
        $this->assertSame(73, $manifest['added_column_count']);
        $this->assertCount(31, $manifest['new_tables']);
        $this->assertCount(73, $manifest['added_columns']);
        $this->assertCount(31, array_unique($manifest['new_tables']));
        $this->assertCount(73, array_unique($manifest['added_columns']));
        $this->assertContains('ai_proposal_items.public_id', $manifest['added_columns']);
        $this->assertNotContains('ai_proposals.evidence', $manifest['added_columns']);
        $this->assertSame(72, $manifest['count_correction']['previously_reported_added_column_count']);
        $this->assertSame(73, $manifest['count_correction']['corrected_added_column_count']);
    }

    public function test_freeze_helper_is_candidate_bound_fail_closed_and_production_free(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g3-rc/Build-G3ReleaseCandidate.ps1'));

        foreach ([
            self::CANDIDATE,
            '7d979ef6a854bce7943740044a3f0c4d942dc617',
            'company-os.ir1.g3-rc-freeze.evidence.v1',
            '[int] $schema.new_table_count -ne 31',
            '[int] $schema.added_column_count -ne 73',
            "'gd'",
            "'System32\\tar.exe'",
            'DETERMINISTIC_BUILD_MISMATCH',
            'post_ir1_scope_mixed_in = $false',
            'connection_attempted = $false',
            'mutation = $false',
        ] as $required) {
            $this->assertStringContainsString($required, $helper);
        }

        foreach (['ssh ', 'scp ', 'sftp ', 'Invoke-WebRequest', 'migrate --force'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $helper);
        }
    }

    public function test_exact_pending_migration_set_is_frozen_in_order(): void
    {
        $helper = (string) file_get_contents(base_path('deployment/g3-rc/Build-G3ReleaseCandidate.ps1'));
        preg_match_all("/'(2026_[^']+)'/", $helper, $matches);

        $this->assertSame([
            '2026_09_19_000001_add_scope_one_contract_to_ai_proposals',
            '2026_09_19_000001_add_scope_two_account_security',
            '2026_09_20_000002_add_scope_three_organization_foundation',
            '2026_09_20_000003_add_scope_four_staff_invitation',
            '2026_09_20_000004_add_scope_five_membership_lifecycle',
            '2026_09_20_000005_add_scope_six_owner_onboarding',
            '2026_09_21_000006_create_scope_seven_business_domains',
            '2026_09_21_000007_add_direction_and_display_order_to_business_domains',
            '2026_09_21_000008_add_product_organization_eligibility',
            '2026_09_24_000001_add_scope_eight_project_action_foundation',
            '2026_09_25_000001_add_scope_nine_action_execution_foundation',
        ], $matches[1]);
    }
}
