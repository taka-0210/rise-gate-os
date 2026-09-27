<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_common_shared_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('host_participant_id');
            $table->unsignedBigInteger('purpose_revision_id');
            $table->string('mode', 24);
            $table->string('state', 24);
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('sequence')->default(0);
            $table->unsignedBigInteger('participant_version');
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->timestamp('started_at_utc')->nullable();
            $table->timestamp('paused_at_utc')->nullable();
            $table->timestamp('interrupted_at_utc')->nullable();
            $table->timestamp('ending_at_utc')->nullable();
            $table->timestamp('ended_at_utc')->nullable();
            $table->unsignedBigInteger('end_cutoff_sequence')->nullable();
            $table->timestamp('hard_stop_at_utc');
            $table->string('safe_error_code', 80)->nullable();
            $table->timestamps();
            $table->foreign('organization_id', 'acses_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_shared_conversation_id', 'acses_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('host_participant_id', 'acses_host_fk')->references('id')->on('ai_common_shared_participants')->restrictOnDelete();
            $table->foreign('purpose_revision_id', 'acses_purpose_fk')->references('id')->on('ai_common_shared_purpose_revisions')->restrictOnDelete();
            $table->unique(['ai_common_shared_conversation_id', 'operation_id'], 'acses_conversation_operation_uq');
            $table->index(['ai_common_shared_conversation_id', 'state'], 'acses_conversation_state_idx');
        });

        Schema::create('ai_common_shared_session_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('ai_common_shared_participant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 20);
            $table->string('status', 20);
            $table->unsignedBigInteger('participant_audience_epoch');
            $table->unsignedBigInteger('membership_access_epoch');
            $table->unsignedBigInteger('credential_generation');
            $table->timestamp('joined_at_utc');
            $table->timestamp('left_at_utc')->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acssp_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('ai_common_shared_participant_id', 'acssp_participant_fk')->references('id')->on('ai_common_shared_participants')->restrictOnDelete();
            $table->foreign('user_id', 'acssp_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'ai_common_shared_participant_id'], 'acssp_session_participant_uq');
            $table->index(['ai_common_shared_session_id', 'status'], 'acssp_session_status_idx');
        });

        Schema::create('ai_common_shared_session_consents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_participant_id');
            $table->unsignedBigInteger('decided_by_user_id');
            $table->string('purpose', 32);
            $table->string('status', 20);
            $table->unsignedBigInteger('revision_no');
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->string('evidence_version', 32);
            $table->timestamp('decided_at_utc');
            $table->timestamp('revoked_at_utc')->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_participant_id', 'acssc_sp_fk')->references('id')->on('ai_common_shared_session_participants')->restrictOnDelete();
            $table->foreign('decided_by_user_id', 'acssc_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_participant_id', 'purpose', 'revision_no'], 'acssc_sp_purpose_revision_uq');
            $table->unique(['ai_common_shared_session_participant_id', 'operation_id', 'purpose'], 'acssc_sp_operation_purpose_uq');
            $table->index(['ai_common_shared_session_participant_id', 'purpose', 'status'], 'acssc_sp_purpose_status_idx');
        });

        Schema::create('ai_common_shared_capture_streams', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('operator_session_participant_id');
            $table->uuid('client_instance_id');
            $table->string('mode', 24);
            $table->string('state', 24);
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('sequence')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->timestamp('started_at_utc');
            $table->timestamp('paused_at_utc')->nullable();
            $table->timestamp('stopped_at_utc')->nullable();
            $table->timestamp('cancelled_at_utc')->nullable();
            $table->timestamp('interrupted_at_utc')->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acscs_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('operator_session_participant_id', 'acscs_operator_fk')->references('id')->on('ai_common_shared_session_participants')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'generation'], 'acscs_session_generation_uq');
            $table->unique(['ai_common_shared_session_id', 'operation_id'], 'acscs_session_operation_uq');
            $table->index(['ai_common_shared_session_id', 'state'], 'acscs_session_state_idx');
        });

        Schema::create('ai_common_shared_audio_windows', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('ai_common_shared_capture_stream_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('sequence');
            $table->string('state', 24);
            $table->unsignedBigInteger('version')->default(1);
            $table->string('storage_key', 500);
            $table->string('mime_type', 120);
            $table->string('extension', 12);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('codec', 40)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->uuid('transcription_operation_id')->nullable();
            $table->char('transcription_payload_fingerprint', 64)->nullable();
            $table->uuid('logical_request_id')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('result_status', 24)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->timestamp('expires_at_utc');
            $table->string('cleanup_status', 24)->default('pending');
            $table->timestamp('cleaned_at_utc')->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acsaw_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('ai_common_shared_capture_stream_id', 'acsaw_stream_fk')->references('id')->on('ai_common_shared_capture_streams')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acsaw_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'operation_id'], 'acsaw_session_operation_uq');
            $table->unique(['ai_common_shared_capture_stream_id', 'sequence'], 'acsaw_stream_sequence_uq');
            $table->unique(['ai_common_shared_session_id', 'transcription_operation_id'], 'acsaw_session_transcription_uq');
            $table->index(['state', 'expires_at_utc'], 'acsaw_state_expiry_idx');
        });

        Schema::create('ai_common_shared_transcript_segments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('ai_common_shared_audio_window_id');
            $table->unsignedBigInteger('ai_common_shared_capture_stream_id');
            $table->unsignedInteger('segment_index');
            $table->string('speaker_label', 24);
            $table->string('speaker_scope', 80);
            $table->unsignedInteger('range_start_ms');
            $table->unsignedInteger('range_end_ms');
            $table->decimal('confidence', 6, 5)->nullable();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acsts_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('ai_common_shared_audio_window_id', 'acsts_window_fk')->references('id')->on('ai_common_shared_audio_windows')->restrictOnDelete();
            $table->foreign('ai_common_shared_capture_stream_id', 'acsts_stream_fk')->references('id')->on('ai_common_shared_capture_streams')->restrictOnDelete();
            $table->unique(['ai_common_shared_audio_window_id', 'segment_index'], 'acsts_window_segment_uq');
            $table->index(['ai_common_shared_session_id', 'range_start_ms'], 'acsts_session_range_idx');
        });

        Schema::create('ai_common_shared_transcript_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_transcript_segment_id');
            $table->unsignedBigInteger('parent_revision_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('revision_no');
            $table->string('kind', 20);
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->text('content');
            $table->char('content_sha256', 64);
            $table->unsignedInteger('range_start_ms');
            $table->unsignedInteger('range_end_ms');
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_transcript_segment_id', 'acstr_segment_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('parent_revision_id', 'acstr_parent_fk')->references('id')->on('ai_common_shared_transcript_revisions')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'acstr_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_shared_transcript_segment_id', 'revision_no'], 'acstr_segment_revision_uq');
            $table->unique(['ai_common_shared_transcript_segment_id', 'operation_id'], 'acstr_segment_operation_uq');
        });

        Schema::table('ai_common_shared_transcript_segments', function (Blueprint $table): void {
            $table->foreign('current_revision_id', 'acsts_revision_fk')->references('id')->on('ai_common_shared_transcript_revisions')->restrictOnDelete();
        });

        Schema::create('ai_common_shared_speaker_relations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('from_segment_id');
            $table->unsignedBigInteger('to_segment_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->uuid('operation_id');
            $table->string('evidence_type', 32);
            $table->string('evidence_reference', 160);
            $table->string('status', 20);
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acssr_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('from_segment_id', 'acssr_from_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('to_segment_id', 'acssr_to_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'acssr_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_shared_session_id', 'operation_id'], 'acssr_session_operation_uq');
            $table->unique(['from_segment_id', 'to_segment_id', 'status'], 'acssr_segments_status_uq');
        });

        Schema::create('ai_common_shared_identity_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_transcript_segment_id');
            $table->unsignedBigInteger('parent_revision_id')->nullable();
            $table->unsignedBigInteger('confirmed_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('revision_no');
            $table->uuid('operation_id');
            $table->string('status', 20);
            $table->string('evidence_type', 32);
            $table->timestamp('confirmed_at_utc')->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_transcript_segment_id', 'acsir_segment_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('parent_revision_id', 'acsir_parent_fk')->references('id')->on('ai_common_shared_identity_revisions')->restrictOnDelete();
            $table->foreign('confirmed_user_id', 'acsir_confirmed_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'acsir_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_shared_transcript_segment_id', 'revision_no'], 'acsir_segment_revision_uq');
            $table->unique(['ai_common_shared_transcript_segment_id', 'operation_id'], 'acsir_segment_operation_uq');
        });

        Schema::table('ai_common_shared_co_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_session_id')->nullable()->after('ai_common_shared_conversation_id');
            $table->string('session_state', 24)->nullable()->after('phase');
            $table->unsignedBigInteger('session_sequence')->default(0)->after('session_state');
            $table->foreign('current_session_id', 'acss_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->index(['current_session_id', 'session_sequence'], 'acss_session_sequence_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'ai_common_shared_identity_revisions',
            'ai_common_shared_speaker_relations',
            'ai_common_shared_transcript_revisions',
            'ai_common_shared_transcript_segments',
            'ai_common_shared_audio_windows',
            'ai_common_shared_capture_streams',
            'ai_common_shared_session_consents',
            'ai_common_shared_session_participants',
            'ai_common_shared_sessions',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Shared Session P3 history exists; destructive rollback is not supported.');
            }
        }
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            Schema::disableForeignKeyConstraints();
        }
        Schema::table('ai_common_shared_co_states', function (Blueprint $table) use ($sqlite): void {
            if ($sqlite) {
                $table->dropForeign(['current_session_id']);
            } else {
                $table->dropForeign('acss_session_fk');
            }
            $table->dropIndex('acss_session_sequence_idx');
            $table->dropColumn(['current_session_id', 'session_state', 'session_sequence']);
        });
        Schema::dropIfExists('ai_common_shared_identity_revisions');
        Schema::dropIfExists('ai_common_shared_speaker_relations');
        if ($sqlite) {
            Schema::dropIfExists('ai_common_shared_transcript_segments');
            Schema::dropIfExists('ai_common_shared_transcript_revisions');
        } else {
            Schema::table('ai_common_shared_transcript_segments', function (Blueprint $table): void {
                $table->dropForeign('acsts_revision_fk');
            });
            Schema::dropIfExists('ai_common_shared_transcript_revisions');
            Schema::dropIfExists('ai_common_shared_transcript_segments');
        }
        Schema::dropIfExists('ai_common_shared_audio_windows');
        Schema::dropIfExists('ai_common_shared_capture_streams');
        Schema::dropIfExists('ai_common_shared_session_consents');
        Schema::dropIfExists('ai_common_shared_session_participants');
        Schema::dropIfExists('ai_common_shared_sessions');
        if ($sqlite) {
            Schema::enableForeignKeyConstraints();
        }
    }
};
