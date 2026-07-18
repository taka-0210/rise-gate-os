<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_access_keys', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('workspace_id')->constrained()->cascadeOnDelete();
            $table->index(['workspace_id', 'user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_access_keys', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'user_id', 'revoked_at']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
