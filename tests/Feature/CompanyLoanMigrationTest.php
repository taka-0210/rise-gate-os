<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyLoanMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_migrations_are_safe_to_resume(): void
    {
        (require database_path('migrations/2026_07_24_000007_create_company_loans_tables.php'))->up();
        (require database_path('migrations/2026_07_24_000008_create_company_loan_balance_snapshots_table.php'))->up();

        $this->assertTrue(Schema::hasTable('company_loans'));
        $this->assertTrue(Schema::hasTable('company_loan_revisions'));
        $this->assertTrue(Schema::hasTable('company_loan_balance_snapshots'));
    }
}
