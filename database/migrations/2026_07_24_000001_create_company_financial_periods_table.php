<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_financial_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_number');
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('status', 20)->default('actual');
            $table->bigInteger('net_sales')->nullable();
            $table->bigInteger('cost_of_sales')->nullable();
            $table->decimal('cost_ratio', 9, 6)->nullable();
            $table->bigInteger('gross_profit')->nullable();
            $table->decimal('gross_profit_ratio', 9, 6)->nullable();
            $table->bigInteger('selling_general_admin_expenses')->nullable();
            $table->decimal('sga_ratio', 9, 6)->nullable();
            $table->bigInteger('operating_profit')->nullable();
            $table->decimal('operating_profit_ratio', 9, 6)->nullable();
            $table->bigInteger('non_operating_income')->nullable();
            $table->bigInteger('non_operating_expenses')->nullable();
            $table->bigInteger('ordinary_profit')->nullable();
            $table->bigInteger('extraordinary_income')->nullable();
            $table->bigInteger('extraordinary_losses')->nullable();
            $table->bigInteger('profit_before_tax')->nullable();
            $table->bigInteger('income_taxes')->nullable();
            $table->bigInteger('net_income')->nullable();
            $table->string('source_filename')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'fiscal_year', 'status'],
                'company_financial_period_unique'
            );
            $table->index(['organization_id', 'period_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_financial_periods');
    }
};
