<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_annual_plan_month_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_annual_plan_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('metric', 80);
            $table->string('status', 20)->default('needs_review');
            $table->bigInteger('accounting_amount')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_annual_plan_id', 'month', 'metric'], 'annual_plan_month_check_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_annual_plan_month_checks');
    }
};
