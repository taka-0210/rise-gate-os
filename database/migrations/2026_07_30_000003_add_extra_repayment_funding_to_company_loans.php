<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_loans', function (Blueprint $table): void {
            $table->string('extra_repayment_funding', 20)
                ->default('self_funded')
                ->after('completed_on');
        });
    }

    public function down(): void
    {
        Schema::table('company_loans', function (Blueprint $table): void {
            $table->dropColumn('extra_repayment_funding');
        });
    }
};
