<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('current_state')->nullable();
            $table->text('desired_state')->nullable();
            $table->text('problem')->nullable();
            $table->text('hypothesis')->nullable();
            $table->text('action')->nullable();
            $table->text('result')->nullable();
            $table->text('impact')->nullable();
            $table->text('next_action')->nullable();
            $table->string('status')->default('proposed');
            $table->string('visibility')->default('internal');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('implemented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('implemented_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'workspace_id']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvements');
    }
};
