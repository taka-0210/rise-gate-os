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
            $table->bigInteger('forecast_net_sales')->nullable()->after('plan_selling_general_admin_expenses');
            $table->bigInteger('forecast_cost_of_sales')->nullable()->after('forecast_net_sales');
            $table->bigInteger('forecast_selling_general_admin_expenses')->nullable()->after('forecast_cost_of_sales');
        });

        DB::table('company_annual_plan_months')
            ->whereNull('actual_net_sales')
            ->update([
                'forecast_net_sales' => DB::raw('plan_net_sales'),
                'forecast_cost_of_sales' => DB::raw('plan_cost_of_sales'),
                'forecast_selling_general_admin_expenses' => DB::raw('plan_selling_general_admin_expenses'),
            ]);
    }

    public function down(): void
    {
        Schema::table('company_annual_plan_months', function (Blueprint $table): void {
            $table->dropColumn([
                'forecast_net_sales',
                'forecast_cost_of_sales',
                'forecast_selling_general_admin_expenses',
            ]);
        });
    }
};
