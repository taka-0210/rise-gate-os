<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('source_proposal_id')->nullable()->constrained('ai_proposals')->nullOnDelete();
            $table->text('change_summary')->nullable();
            $table->json('previous_snapshot');
            $table->json('plan_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['project_id', 'version_number']);
        });

        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->foreignId('applied_plan_version_id')
                ->nullable()
                ->after('applied_at')
                ->constrained('project_plan_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('applied_plan_version_id');
        });
        Schema::dropIfExists('project_plan_versions');
    }
};
