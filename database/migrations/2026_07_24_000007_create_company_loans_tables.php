<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_loans')) {
            Schema::create('company_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('financial_institution');
            $table->string('management_number', 50);
            $table->string('purpose')->nullable();
            $table->date('executed_on')->nullable();
            $table->string('term_label', 50)->nullable();
            $table->bigInteger('original_amount')->default(0);
            $table->bigInteger('current_balance')->default(0);
            $table->bigInteger('monthly_principal_payment')->default(0);
            $table->decimal('annual_interest_rate', 7, 4)->nullable();
            $table->string('interest_type', 20)->nullable();
            $table->bigInteger('recent_interest_amount')->default(0);
            $table->date('maturity_on')->nullable();
            $table->string('guarantee_type')->nullable();
            $table->string('repayment_day', 20)->nullable();
            $table->date('balance_as_of')->nullable();
            $table->string('loan_status', 20)->default('active');
            $table->string('record_status', 20)->default('draft');
            $table->string('source_type', 20)->default('manual');
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'financial_institution', 'management_number'], 'company_loans_unique');
            $table->index(['organization_id', 'loan_status']);
            });
        }

        if (! Schema::hasTable('company_loan_revisions')) {
            Schema::create('company_loan_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_loan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 20);
                $table->json('before_data')->nullable();
                $table->json('after_data');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_loan_revisions');
        Schema::dropIfExists('company_loans');
    }
};
