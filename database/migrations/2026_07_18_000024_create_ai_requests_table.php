<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('claimed_by_access_key_id')->nullable()->constrained('ai_access_keys')->nullOnDelete();
            $table->foreignId('ai_proposal_id')->nullable()->constrained('ai_proposals')->nullOnDelete();
            $table->string('title');
            $table->text('instructions');
            $table->string('status')->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'status', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('ai_requests'); }
};
