<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24)->default('manual');
            $table->string('status', 24)->default('pending');
            $table->text('completed_work');
            $table->text('next_work');
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'idempotency_key']);
            $table->index(['project_id', 'status', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_handoffs');
    }
};
