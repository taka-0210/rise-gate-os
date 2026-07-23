<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->string('file_change_path')->nullable()->after('image_size');
            $table->longText('file_change_content')->nullable()->after('file_change_path');
            $table->string('file_change_original_hash', 64)->nullable()->after('file_change_content');
            $table->string('file_change_status', 20)->nullable()->after('file_change_original_hash');
            $table->timestamp('file_change_applied_at')->nullable()->after('file_change_status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'file_change_path', 'file_change_content', 'file_change_original_hash',
                'file_change_status', 'file_change_applied_at',
            ]);
        });
    }
};
