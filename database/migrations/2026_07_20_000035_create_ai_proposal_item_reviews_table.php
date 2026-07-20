<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_proposal_item_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_proposal_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('comment')->nullable();
            $table->foreignId('merge_target_item_id')->nullable()->constrained('ai_proposal_items')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['action', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposal_item_reviews');
    }
};
