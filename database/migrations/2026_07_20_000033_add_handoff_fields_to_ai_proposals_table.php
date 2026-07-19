<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->foreignId('handed_off_by')->nullable()->after('applied_at')->constrained('users')->nullOnDelete();
            $table->timestamp('handed_off_at')->nullable()->after('handed_off_by');
        });
    }

    public function down(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('handed_off_by');
            $table->dropColumn('handed_off_at');
        });
    }
};
