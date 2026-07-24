<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_loans', 'completed_on')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->date('completed_on')->nullable()->after('maturity_on');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_loans', 'completed_on')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->dropColumn('completed_on');
            });
        }
    }
};
