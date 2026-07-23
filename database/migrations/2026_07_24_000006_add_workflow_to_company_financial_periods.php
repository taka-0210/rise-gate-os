<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_financial_periods', function (Blueprint $table): void {
            $table->string('record_status', 20)->default('confirmed')->after('status');
            $table->string('source_type', 20)->default('import')->after('record_status');
            $table->timestamp('confirmed_at')->nullable()->after('imported_at');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('company_financial_period_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_financial_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->json('before_data')->nullable();
            $table->json('after_data');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_financial_period_revisions');
        Schema::table('company_financial_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['record_status', 'source_type', 'confirmed_at']);
        });
    }
};
