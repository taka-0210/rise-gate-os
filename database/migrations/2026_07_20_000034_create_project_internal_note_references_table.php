<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_internal_note_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_internal_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('title')->nullable();
            $table->text('reference_points')->nullable();
            $table->text('avoid_points')->nullable();
            $table->boolean('share_with_ai')->default(true);
            $table->timestamps();
            $table->index(['project_id', 'share_with_ai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_internal_note_references');
    }
};
