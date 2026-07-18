<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_proposal_items', function (Blueprint $table): void {
            $table->string('reference_key')->nullable()->after('target_public_id');
            $table->string('parent_reference')->nullable()->after('reference_key');
            $table->unique(['ai_proposal_id', 'reference_key']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_proposal_items', function (Blueprint $table): void {
            $table->dropUnique(['ai_proposal_id', 'reference_key']);
            $table->dropColumn(['reference_key', 'parent_reference']);
        });
    }
};
