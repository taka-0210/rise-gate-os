<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('image_input_tokens')->nullable();
            $table->unsignedBigInteger('image_output_tokens')->nullable();
            $table->json('provider_usage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->dropColumn(['image_input_tokens', 'image_output_tokens', 'provider_usage']);
        });
    }
};
