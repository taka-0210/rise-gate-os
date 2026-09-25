<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_run_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('run_type', 24);
            $table->string('category', 32)->default('task');
            $table->string('control_status', 16)->default('active');
            $table->unsignedBigInteger('current_revision_id')->nullable()->index();
            $table->string('communication_target', 500)->nullable();
            $table->text('communication_draft')->nullable();
            $table->time('communication_time')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'project_id'], 'ars_org_project_idx');
        });

        Schema::create('action_schedule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('action_run_setting_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('frequency', 16);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->boolean('month_end')->default(false);
            $table->string('execution_rule', 24)->default('on_date');
            $table->unsignedSmallInteger('window_days_before')->default(0);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('count_limit')->nullable();
            $table->date('effective_from');
            $table->date('generated_through')->nullable();
            $table->string('timezone', 64)->default('Asia/Tokyo');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['action_run_setting_id', 'revision_number'], 'asr_setting_revision_uq');
            $table->index(['project_id', 'effective_from'], 'asr_project_effective_idx');
        });

        Schema::table('action_run_settings', function (Blueprint $table): void {
            $table->foreign('current_revision_id', 'ars_current_revision_fk')->references('id')->on('action_schedule_revisions')->restrictOnDelete();
        });

        Schema::create('action_executions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('schedule_revision_id')->nullable()->constrained('action_schedule_revisions')->restrictOnDelete();
            $table->foreignId('source_execution_id')->nullable()->constrained('action_executions')->restrictOnDelete();
            $table->string('origin', 16)->default('regular');
            $table->string('slot_key', 190)->unique();
            $table->string('retry_key', 190)->nullable()->unique();
            $table->date('scheduled_date');
            $table->dateTime('window_starts_at_utc');
            $table->dateTime('window_ends_at_utc');
            $table->string('status', 16)->default('planned');
            $table->foreignId('planned_assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('performed_at_utc')->nullable();
            $table->dateTime('reported_at_utc')->nullable();
            $table->dateTime('resolved_at_utc')->nullable();
            $table->text('memo')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'planned_assignee_id', 'status'], 'ae_today_assignee_idx');
            $table->index(['project_id', 'task_id', 'scheduled_date'], 'ae_project_task_date_idx');
        });

        Schema::create('action_execution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_execution_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->string('payload_fingerprint', 64);
            $table->string('event', 40);
            $table->string('actor_type', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('occurred_at_utc');
            $table->timestamps();
            $table->unique(['action_execution_id', 'operation_id'], 'aee_execution_operation_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_execution_events');
        Schema::dropIfExists('action_executions');
        Schema::table('action_run_settings', fn (Blueprint $table) => $table->dropForeign('ars_current_revision_fk'));
        Schema::dropIfExists('action_schedule_revisions');
        Schema::dropIfExists('action_run_settings');
    }
};
