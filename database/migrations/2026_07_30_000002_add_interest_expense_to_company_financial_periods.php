<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_financial_periods', function (Blueprint $table): void {
            $table->bigInteger('interest_expense')->nullable()->after('non_operating_expenses');
        });
    }

    public function down(): void
    {
        Schema::table('company_financial_periods', function (Blueprint $table): void {
            $table->dropColumn('interest_expense');
        });
    }
};
