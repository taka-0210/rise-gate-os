<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('content');
            $table->string('image_name')->nullable()->after('image_path');
            $table->string('image_mime', 100)->nullable()->after('image_name');
            $table->unsignedInteger('image_size')->nullable()->after('image_mime');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'image_name', 'image_mime', 'image_size']);
        });
    }
};
