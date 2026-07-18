<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 150);
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['ai_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_attachments');
    }
};
