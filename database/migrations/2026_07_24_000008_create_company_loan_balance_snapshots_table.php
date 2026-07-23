<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_loan_balance_snapshots')) {
            Schema::create('company_loan_balance_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_loan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->date('balance_as_of');
                $table->bigInteger('balance');
                $table->bigInteger('monthly_principal_payment')->default(0);
                $table->bigInteger('interest_amount')->default(0);
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['company_loan_id', 'balance_as_of'], 'company_loan_balance_date_unique');
                $table->index(['organization_id', 'balance_as_of']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_loan_balance_snapshots');
    }
};
