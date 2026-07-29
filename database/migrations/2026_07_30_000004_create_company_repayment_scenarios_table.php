<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_repayment_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->bigInteger('net_sales')->nullable();
            $table->bigInteger('net_income')->nullable();
            $table->bigInteger('depreciation_expense')->nullable();
            $table->bigInteger('interest_expense')->nullable();
            $table->boolean('execute_extra_repayments')->default(true);
            $table->json('extra_repayment_funding_overrides')->nullable();
            $table->json('new_loans')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_repayment_scenarios');
    }
};
