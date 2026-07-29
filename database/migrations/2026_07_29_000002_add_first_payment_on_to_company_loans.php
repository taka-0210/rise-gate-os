<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_loans', 'first_payment_on')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->date('first_payment_on')->nullable()->after('scheduled_payment_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_loans', 'first_payment_on')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->dropColumn('first_payment_on');
            });
        }
    }
};
