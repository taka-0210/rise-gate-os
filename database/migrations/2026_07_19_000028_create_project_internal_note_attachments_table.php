<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may leave the empty table behind when adding a foreign key fails.
        // This migration has not completed in that case, so recreate only this new table.
        Schema::dropIfExists('project_internal_note_attachments');

        Schema::create('project_internal_note_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('project_internal_note_id');
            $table->foreignId('project_id');
            $table->foreignId('uploaded_by');
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 150);
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
            $table->foreign('project_internal_note_id', 'pina_note_fk')
                ->references('id')->on('project_internal_notes')->cascadeOnDelete();
            $table->foreign('project_id', 'pina_project_fk')
                ->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('uploaded_by', 'pina_uploader_fk')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_internal_note_attachments');
    }
};
