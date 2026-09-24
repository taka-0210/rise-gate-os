<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('purpose')->nullable();
            $table->text('expected_outcome')->nullable();
            $table->string('execution_contract_version', 30)->nullable()->index('projects_execution_contract_idx');
            $table->string('visibility', 20)->nullable()->index('projects_visibility_idx');
            $table->text('confidential_reason')->nullable();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_status', 20)->default('not_required');
            $table->foreignId('review_requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_requested_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('completion_check_version')->nullable();
        });

        Schema::table('project_members', function (Blueprint $table): void {
            $table->timestamp('left_at')->nullable();
            $table->foreignId('left_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
        });

        Schema::create('project_member_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_member_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_member_id', 'role'], 'project_member_roles_member_role_uq');
            $table->index(['role', 'revoked_at'], 'project_member_roles_active_idx');
        });

        Schema::create('project_group_audiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_group_id')->constrained('organization_groups')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'organization_group_id'], 'project_group_audience_uq');
        });

        Schema::table('roadmaps', function (Blueprint $table): void {
            $table->unsignedBigInteger('completion_check_version')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('improvements', function (Blueprint $table): void {
            $table->text('theme_description')->nullable();
            $table->string('execution_status', 20)->nullable();
            $table->unsignedBigInteger('completion_check_version')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->text('done_condition')->nullable();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_status', 20)->default('not_required');
            $table->foreignId('review_requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_requested_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('last_change_reason')->nullable();
        });

        Schema::create('project_execution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('subject_public_id', 26)->nullable()->index('project_events_subject_public_idx');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->string('source', 30)->default('human');
            $table->string('operation_id', 64);
            $table->unsignedBigInteger('entity_version')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['project_id', 'operation_id'], 'project_execution_event_operation_uq');
            $table->index(['project_id', 'entity_type', 'entity_id'], 'project_execution_event_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_execution_events');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewer_user_id');
            $table->dropConstrainedForeignId('review_requested_by_user_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('completed_by_user_id');
            $table->dropConstrainedForeignId('reopened_by_user_id');
            $table->dropColumn(['done_condition', 'review_status', 'review_requested_at', 'reviewed_at', 'reopened_at', 'last_change_reason']);
        });
        Schema::table('improvements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completed_by_user_id');
            $table->dropColumn(['theme_description', 'execution_status', 'completion_check_version']);
        });
        Schema::table('roadmaps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completed_by_user_id');
            $table->dropColumn('completion_check_version');
        });
        Schema::dropIfExists('project_group_audiences');
        Schema::dropIfExists('project_member_roles');
        Schema::table('project_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('left_by_user_id');
            $table->dropColumn(['left_at', 'status_reason']);
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex('projects_execution_contract_idx');
            $table->dropIndex('projects_visibility_idx');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewer_user_id');
            $table->dropConstrainedForeignId('review_requested_by_user_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn([
                'purpose', 'expected_outcome', 'execution_contract_version', 'visibility',
                'confidential_reason', 'review_status', 'review_requested_at', 'reviewed_at',
                'completion_check_version',
            ]);
        });
    }
};
