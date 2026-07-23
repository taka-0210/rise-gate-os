<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyFinanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_migration_can_resume_after_a_partial_application(): void
    {
        $migration = require database_path('migrations/2026_07_24_000006_add_workflow_to_company_financial_periods.php');

        $migration->up();

        $this->assertTrue(\Schema::hasColumn('company_financial_periods', 'record_status'));
        $this->assertTrue(\Schema::hasColumn('company_financial_periods', 'source_type'));
        $this->assertTrue(\Schema::hasColumn('company_financial_periods', 'confirmed_at'));
        $this->assertTrue(\Schema::hasColumn('company_financial_periods', 'confirmed_by'));
        $this->assertTrue(\Schema::hasTable('company_financial_period_revisions'));
    }
}
