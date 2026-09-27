<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_common_shared_transcript_chunks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('ordinal');
            $table->unsignedBigInteger('first_segment_id');
            $table->unsignedBigInteger('last_segment_id');
            $table->unsignedInteger('range_start_ms');
            $table->unsignedInteger('range_end_ms');
            $table->text('content');
            $table->char('content_sha256', 64);
            $table->unsignedInteger('character_count');
            $table->string('status', 20)->default('current');
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acs_tc_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('first_segment_id', 'acs_tc_first_segment_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('last_segment_id', 'acs_tc_last_segment_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'ordinal'], 'acs_tc_session_ordinal_uq');
            $table->index(['ai_common_shared_session_id', 'status', 'ordinal'], 'acs_tc_session_status_idx');
        });

        Schema::create('ai_common_shared_context_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('previous_checkpoint_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('purpose_revision_id');
            $table->unsignedBigInteger('revision_no');
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->char('audience_fingerprint', 64);
            $table->unsignedBigInteger('through_segment_id');
            $table->unsignedBigInteger('through_revision_id');
            $table->string('status', 20);
            $table->longText('structured_context');
            $table->unsignedInteger('estimated_tokens');
            $table->char('lineage_fingerprint', 64);
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acs_cc_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('previous_checkpoint_id', 'acs_cc_previous_fk')->references('id')->on('ai_common_shared_context_checkpoints')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'acs_cc_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('purpose_revision_id', 'acs_cc_purpose_fk')->references('id')->on('ai_common_shared_purpose_revisions')->restrictOnDelete();
            $table->foreign('through_segment_id', 'acs_cc_segment_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('through_revision_id', 'acs_cc_revision_fk')->references('id')->on('ai_common_shared_transcript_revisions')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'revision_no'], 'acs_cc_session_revision_uq');
            $table->unique(['ai_common_shared_session_id', 'operation_id'], 'acs_cc_session_operation_uq');
            $table->index(['ai_common_shared_session_id', 'status'], 'acs_cc_session_status_idx');
        });

        Schema::create('ai_common_shared_checkpoint_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_shared_context_checkpoint_id');
            $table->unsignedBigInteger('transcript_segment_id');
            $table->unsignedBigInteger('transcript_revision_id');
            $table->unsignedBigInteger('identity_revision_id')->nullable();
            $table->string('speaker_label', 24);
            $table->string('speaker_scope', 80);
            $table->unsignedInteger('range_start_ms');
            $table->unsignedInteger('range_end_ms');
            $table->char('dependency_fingerprint', 64);
            $table->timestamps();
            $table->foreign('ai_common_shared_context_checkpoint_id', 'acs_cd_checkpoint_fk')->references('id')->on('ai_common_shared_context_checkpoints')->restrictOnDelete();
            $table->foreign('transcript_segment_id', 'acs_cd_segment_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('transcript_revision_id', 'acs_cd_revision_fk')->references('id')->on('ai_common_shared_transcript_revisions')->restrictOnDelete();
            $table->foreign('identity_revision_id', 'acs_cd_identity_fk')->references('id')->on('ai_common_shared_identity_revisions')->restrictOnDelete();
            $table->unique(['ai_common_shared_context_checkpoint_id', 'transcript_segment_id'], 'acs_cd_checkpoint_segment_uq');
        });

        Schema::create('ai_common_shared_device_cursors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('user_id');
            $table->uuid('client_instance_id');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('last_seen_at_utc');
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acs_dc_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('user_id', 'acs_dc_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'client_instance_id'], 'acs_dc_session_client_uq');
            $table->index(['ai_common_shared_session_id', 'user_id'], 'acs_dc_session_user_idx');
        });

        Schema::create('ai_common_shared_session_end_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('context_checkpoint_id');
            $table->uuid('operation_id');
            $table->uuid('logical_request_id');
            $table->char('payload_fingerprint', 64);
            $table->string('state', 24);
            $table->string('safe_error_code', 80)->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acs_ser_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acs_ser_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('context_checkpoint_id', 'acs_ser_checkpoint_fk')->references('id')->on('ai_common_shared_context_checkpoints')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'operation_id'], 'acs_ser_session_operation_uq');
        });

        Schema::create('ai_common_shared_session_end_candidates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('session_end_run_id');
            $table->string('kind', 32);
            $table->unsignedInteger('ordinal');
            $table->text('content');
            $table->char('content_sha256', 64);
            $table->longText('provenance');
            $table->string('status', 24)->default('candidate');
            $table->timestamps();
            $table->foreign('session_end_run_id', 'acs_sec_run_fk')->references('id')->on('ai_common_shared_session_end_runs')->restrictOnDelete();
            $table->unique(['session_end_run_id', 'ordinal'], 'acs_sec_run_ordinal_uq');
        });

        Schema::table('ai_common_shared_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('context_current_checkpoint_id')->nullable();
            $table->unsignedBigInteger('context_watermark_segment_id')->nullable();
            $table->unsignedBigInteger('context_watermark_revision_id')->nullable();
            $table->unsignedBigInteger('context_dirty_from_segment_id')->nullable();
            $table->string('context_status', 20)->default('empty');
            $table->unsignedBigInteger('room_sequence')->default(0);
        });

        Schema::table('ai_common_shared_ai_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('context_checkpoint_id')->nullable();
            $table->foreign('context_checkpoint_id', 'acsr_context_checkpoint_fk')->references('id')->on('ai_common_shared_context_checkpoints')->restrictOnDelete();
        });

        Schema::table('ai_common_shared_co_states', function (Blueprint $table): void {
            $table->string('presence_state', 24)->default('ready');
            $table->string('capture_state', 24)->default('inactive');
            $table->string('asr_state', 24)->default('idle');
            $table->string('context_state', 24)->default('empty');
            $table->unsignedBigInteger('context_watermark_segment_id')->nullable();
            $table->unsignedBigInteger('room_sequence')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['ai_common_shared_session_end_candidates', 'ai_common_shared_session_end_runs', 'ai_common_shared_device_cursors', 'ai_common_shared_checkpoint_dependencies', 'ai_common_shared_context_checkpoints', 'ai_common_shared_transcript_chunks'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Shared AI P4 evidence exists; destructive rollback is not supported.');
            }
        }
        Schema::table('ai_common_shared_co_states', function (Blueprint $table): void {
            $table->dropColumn(['presence_state', 'capture_state', 'asr_state', 'context_state', 'context_watermark_segment_id', 'room_sequence']);
        });
        Schema::table('ai_common_shared_ai_requests', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['context_checkpoint_id']);
            } else {
                $table->dropForeign('acsr_context_checkpoint_fk');
            }
            $table->dropColumn('context_checkpoint_id');
        });
        Schema::table('ai_common_shared_sessions', function (Blueprint $table): void {
            $table->dropColumn(['context_current_checkpoint_id', 'context_watermark_segment_id', 'context_watermark_revision_id', 'context_dirty_from_segment_id', 'context_status', 'room_sequence']);
        });
        Schema::dropIfExists('ai_common_shared_session_end_candidates');
        Schema::dropIfExists('ai_common_shared_session_end_runs');
        Schema::dropIfExists('ai_common_shared_device_cursors');
        Schema::dropIfExists('ai_common_shared_checkpoint_dependencies');
        Schema::dropIfExists('ai_common_shared_context_checkpoints');
        Schema::dropIfExists('ai_common_shared_transcript_chunks');
    }
};
