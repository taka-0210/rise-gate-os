<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_proposal_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_proposal_id')->constrained()->cascadeOnDelete();
            $table->string('operation');
            $table->string('entity_type');
            $table->string('target_public_id')->nullable();
            $table->json('attributes');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('validation_status')->default('pending');
            $table->text('validation_message')->nullable();
            $table->timestamps();

            $table->index(['ai_proposal_id', 'sort_order']);
            $table->index(['entity_type', 'target_public_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposal_items');
    }
};
