<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_annual_plan_months', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_annual_plan_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->bigInteger('plan_net_sales')->nullable();
            $table->bigInteger('actual_net_sales')->nullable();
            $table->bigInteger('actual_cost_of_sales')->nullable();
            $table->bigInteger('actual_selling_general_admin_expenses')->nullable();
            $table->timestamps();

            $table->unique(['company_annual_plan_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_annual_plan_months');
    }
};
