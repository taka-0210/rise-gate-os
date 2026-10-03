<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_management_periods', function (Blueprint $table): void {
            $table->unsignedInteger('fiscal_term_number')->nullable()->after('name');
            $table->unique(
                ['organization_id', 'fiscal_term_number'],
                'org_mgmt_periods_org_term_unique',
            );
        });

        Schema::table('organization_management_period_versions', function (Blueprint $table): void {
            $table->unsignedInteger('fiscal_term_number')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organization_management_period_versions', function (Blueprint $table): void {
            $table->dropColumn('fiscal_term_number');
        });

        Schema::table('organization_management_periods', function (Blueprint $table): void {
            $table->dropUnique('org_mgmt_periods_org_term_unique');
            $table->dropColumn('fiscal_term_number');
        });
    }
};
