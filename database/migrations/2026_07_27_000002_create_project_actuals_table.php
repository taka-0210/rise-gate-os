<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_actuals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_proposal_id')->nullable()->constrained('ai_proposals')->nullOnDelete();
            $table->string('related_entity_type', 30)->nullable();
            $table->ulid('related_entity_public_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('result')->nullable();
            $table->timestamp('actual_started_at')->nullable();
            $table->timestamp('actual_completed_at')->nullable();
            $table->unsignedInteger('effort_minutes')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // Older production MySQL configurations reject a required TIMESTAMP
            // without a server-side default. The application always supplies this
            // value, so DATETIME is the compatible and semantically correct type.
            $table->dateTime('recorded_at');
            $table->timestamps();
            $table->index(['project_id', 'actual_completed_at']);
            $table->index(['related_entity_type', 'related_entity_public_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_actuals');
    }
};
