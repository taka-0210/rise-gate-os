<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL can leave this new table behind when a later foreign-key step fails.
        // Since the migration is not recorded in that case, recreate the incomplete table on retry.
        Schema::dropIfExists('project_internal_note_references');

        Schema::create('project_internal_note_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_internal_note_id');
            $table->foreignId('project_id');
            $table->text('url');
            $table->string('title')->nullable();
            $table->text('reference_points')->nullable();
            $table->text('avoid_points')->nullable();
            $table->boolean('share_with_ai')->default(true);
            $table->timestamps();
            $table->index(['project_id', 'share_with_ai']);
            $table->foreign('project_internal_note_id', 'pinr_note_fk')
                ->references('id')->on('project_internal_notes')->cascadeOnDelete();
            $table->foreign('project_id', 'pinr_project_fk')
                ->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_internal_note_references');
    }
};
