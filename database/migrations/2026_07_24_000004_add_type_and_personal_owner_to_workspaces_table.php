<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('type', 20)->default('shared')->after('purpose');
            $table->foreignId('personal_owner_user_id')
                ->nullable()
                ->after('type')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'type']);
            $table->dropConstrainedForeignId('personal_owner_user_id');
            $table->dropColumn('type');
        });
    }
};
