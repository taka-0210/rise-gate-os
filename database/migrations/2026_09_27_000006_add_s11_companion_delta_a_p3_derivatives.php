<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_common_attachments', function (Blueprint $table): void {
            $table->string('media_codec', 40)->nullable()->after('inspection_safe_code');
            $table->unsignedInteger('duration_ms')->nullable()->after('media_codec');
        });

        Schema::create('ai_common_attachment_derivatives', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_attachment_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->string('kind', 32);
            $table->string('state', 24);
            $table->unsignedBigInteger('attachment_version');
            $table->char('source_sha256', 64);
            $table->json('selector');
            $table->char('selector_fingerprint', 64);
            $table->string('extractor_driver', 64)->nullable();
            $table->string('extractor_version', 64)->nullable();
            $table->longText('content')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedInteger('character_count')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamps();

            $table->foreign('ai_common_attachment_id', 'acad_attachment_fk')
                ->references('id')->on('ai_common_attachments')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'acad_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['ai_common_attachment_id', 'created_by_user_id', 'operation_id'],
                'acad_actor_operation_uq'
            );
            $table->index(['ai_common_attachment_id', 'state', 'id'], 'acad_attachment_state_idx');
            $table->index(['kind', 'selector_fingerprint'], 'acad_selector_idx');
        });

        Schema::create('ai_common_transcript_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_attachment_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('parent_revision_id')->nullable();
            $table->uuid('operation_id')->nullable();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->unsignedInteger('revision_number');
            $table->string('kind', 24);
            $table->longText('content');
            $table->char('content_sha256', 64);
            $table->char('audio_sha256', 64);
            $table->unsignedInteger('range_start_ms')->nullable();
            $table->unsignedInteger('range_end_ms')->nullable();
            $table->string('range_precision', 24);
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->timestamps();

            $table->foreign('ai_common_attachment_id', 'actr_attachment_fk')
                ->references('id')->on('ai_common_attachments')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'actr_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('parent_revision_id', 'actr_parent_fk')
                ->references('id')->on('ai_common_transcript_revisions')->restrictOnDelete();
            $table->unique(['ai_common_attachment_id', 'revision_number'], 'actr_attachment_revision_uq');
            $table->unique(
                ['ai_common_attachment_id', 'created_by_user_id', 'operation_id'],
                'actr_actor_operation_uq'
            );
            $table->index(['ai_common_attachment_id', 'id'], 'actr_attachment_idx');
        });

        Schema::create('ai_common_attachment_transcription_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_attachment_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('ai_common_transcript_revision_id')->nullable();
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->string('logical_request_id', 64);
            $table->string('result_status', 24);
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->foreign('ai_common_attachment_id', 'acato_attachment_fk')
                ->references('id')->on('ai_common_attachments')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acato_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ai_common_transcript_revision_id', 'acato_revision_fk')
                ->references('id')->on('ai_common_transcript_revisions')->restrictOnDelete();
            $table->unique(
                ['ai_common_attachment_id', 'actor_user_id', 'operation_id'],
                'acato_actor_operation_uq'
            );
            $table->index(['result_status', 'created_at'], 'acato_result_idx');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('ai_common_attachment_derivatives')
                && DB::table('ai_common_attachment_derivatives')->exists())
            || (Schema::hasTable('ai_common_transcript_revisions')
                && DB::table('ai_common_transcript_revisions')->exists())
            || (Schema::hasTable('ai_common_attachment_transcription_operations')
                && DB::table('ai_common_attachment_transcription_operations')->exists())) {
            throw new RuntimeException(
                'Delta A P3 derivative or transcript evidence exists. Preserve immutable history; destructive rollback is not supported.'
            );
        }

        Schema::dropIfExists('ai_common_attachment_transcription_operations');
        Schema::dropIfExists('ai_common_transcript_revisions');
        Schema::dropIfExists('ai_common_attachment_derivatives');
        Schema::table('ai_common_attachments', function (Blueprint $table): void {
            $table->dropColumn(['media_codec', 'duration_ms']);
        });
    }
};
