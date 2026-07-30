<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_annual_plan_months', function (Blueprint $table): void {
            $table->bigInteger('plan_cost_of_sales')->nullable()->after('plan_net_sales');
            $table->bigInteger('plan_selling_general_admin_expenses')->nullable()->after('plan_cost_of_sales');
        });

        DB::table('company_annual_plans')->orderBy('id')->each(function ($plan): void {
            $months = DB::table('company_annual_plan_months')
                ->where('company_annual_plan_id', $plan->id)
                ->orderBy('month')
                ->get();
            if ($months->isEmpty()) {
                return;
            }

            $planSales = (int) ($plan->plan_net_sales ?? 0);
            $planCost = $planSales - (int) ($plan->plan_gross_profit ?? 0);
            $costRate = $planSales > 0 ? $planCost / $planSales : 0;
            $sgaBase = intdiv((int) ($plan->plan_selling_general_admin_expenses ?? 0), $months->count());

            foreach ($months as $index => $month) {
                DB::table('company_annual_plan_months')->where('id', $month->id)->update([
                    'plan_cost_of_sales' => (int) round((int) ($month->plan_net_sales ?? 0) * $costRate),
                    'plan_selling_general_admin_expenses' => $index === $months->count() - 1
                        ? (int) ($plan->plan_selling_general_admin_expenses ?? 0) - ($sgaBase * ($months->count() - 1))
                        : $sgaBase,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_annual_plan_months', function (Blueprint $table): void {
            $table->dropColumn([
                'plan_cost_of_sales',
                'plan_selling_general_admin_expenses',
            ]);
        });
    }
};
