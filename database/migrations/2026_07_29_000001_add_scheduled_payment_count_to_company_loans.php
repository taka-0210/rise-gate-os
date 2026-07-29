<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_loans', 'scheduled_payment_count')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->unsignedInteger('scheduled_payment_count')->nullable()->after('term_label');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_loans', 'scheduled_payment_count')) {
            Schema::table('company_loans', function (Blueprint $table): void {
                $table->dropColumn('scheduled_payment_count');
            });
        }
    }
};
