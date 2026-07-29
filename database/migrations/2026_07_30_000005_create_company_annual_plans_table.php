<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_annual_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('period_number')->nullable();
            foreach (['plan', 'forecast'] as $kind) {
                $table->bigInteger($kind.'_net_sales')->nullable();
                $table->bigInteger($kind.'_gross_profit')->nullable();
                $table->bigInteger($kind.'_selling_general_admin_expenses')->nullable();
                $table->bigInteger($kind.'_net_income')->nullable();
                $table->bigInteger($kind.'_interest_expense')->nullable();
                $table->bigInteger($kind.'_depreciation_expense')->nullable();
            }
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_annual_plans');
    }
};
