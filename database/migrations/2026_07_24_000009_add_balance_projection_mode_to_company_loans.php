<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_loans', 'balance_projection_mode')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->string('balance_projection_mode', 30)->default('amortizing')->after('monthly_principal_payment');
            });
        }

        DB::table('company_loans')
            ->where('monthly_principal_payment', 0)
            ->where('current_balance', '>', 0)
            ->update(['balance_projection_mode' => 'hold']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_loans', 'balance_projection_mode')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->dropColumn('balance_projection_mode');
            });
        }
    }
};
