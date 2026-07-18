<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_access_key_id')->nullable()->constrained('ai_access_keys')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('tool_name')->nullable();
            $table->boolean('succeeded');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'succeeded']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_logs');
    }
};
