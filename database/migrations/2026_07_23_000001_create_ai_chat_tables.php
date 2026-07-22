<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_threads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('AIパートナーとの会話');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['workspace_id', 'updated_at']);
        });

        Schema::create('ai_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_chat_thread_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('context_key')->nullable();
            $table->string('context_label')->nullable();
            $table->string('model')->nullable();
            $table->string('provider_response_id')->nullable()->index();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('estimated_cost_microusd')->nullable();
            $table->timestamps();

            $table->index(['ai_chat_thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_threads');
    }
};
