<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_ai_policies', function (Blueprint $table): void {
            $table->boolean('allows_transcription')->default(false)->after('is_enabled');
        });

        Schema::table('ai_common_attachments', function (Blueprint $table): void {
            $table->string('inspection_status', 24)->default('pending')->after('storage_key');
            $table->string('inspection_driver', 40)->nullable()->after('inspection_status');
            $table->string('inspection_version', 64)->nullable()->after('inspection_driver');
            $table->string('inspection_safe_code', 64)->nullable()->after('inspection_version');
            $table->timestamp('inspected_at_utc')->nullable()->after('inspection_safe_code');
            $table->index(['inspection_status', 'created_at'], 'aca_inspection_idx');
        });

        Schema::create('ai_common_temporary_audios', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_conversation_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->string('state', 24);
            $table->unsignedBigInteger('version')->default(1);
            $table->string('storage_key', 500);
            $table->string('mime_type', 120);
            $table->string('extension', 12);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('codec', 40)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('draft_text')->nullable();
            $table->unsignedBigInteger('posted_message_id')->nullable();
            $table->timestamp('transcription_consented_at_utc')->nullable();
            $table->timestamp('expires_at_utc');
            $table->string('cleanup_status', 24)->default('pending');
            $table->timestamp('cleaned_at_utc')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'acta_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_conversation_id', 'acta_conversation_fk')->references('id')->on('ai_common_conversations')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acta_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_message_id', 'acta_message_fk')->references('id')->on('ai_common_messages')->restrictOnDelete();
            $table->unique(['ai_common_conversation_id', 'actor_user_id', 'operation_id'], 'acta_actor_operation_uq');
            $table->index(['state', 'expires_at_utc'], 'acta_expiry_idx');
        });

        Schema::create('ai_common_transcription_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_temporary_audio_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('operation_id');
            $table->char('payload_fingerprint', 64);
            $table->string('logical_request_id', 64);
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('result_status', 24);
            $table->string('safe_error_code', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->foreign('ai_common_temporary_audio_id', 'acto_audio_fk')->references('id')->on('ai_common_temporary_audios')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acto_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ai_common_temporary_audio_id', 'actor_user_id', 'operation_id'], 'acto_actor_operation_uq');
            $table->index(['result_status', 'created_at'], 'acto_result_idx');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('ai_common_temporary_audios') && DB::table('ai_common_temporary_audios')->exists())
            || (Schema::hasColumn('ai_common_attachments', 'inspected_at_utc')
                && DB::table('ai_common_attachments')->whereNotNull('inspected_at_utc')->exists())
            || (Schema::hasColumn('organization_ai_policies', 'allows_transcription')
                && DB::table('organization_ai_policies')->where('allows_transcription', true)->exists())) {
            throw new RuntimeException(
                'Delta A P2 input evidence exists. Disable the feature and preserve history; destructive rollback is not supported.'
            );
        }

        Schema::dropIfExists('ai_common_transcription_operations');
        Schema::dropIfExists('ai_common_temporary_audios');
        Schema::table('ai_common_attachments', function (Blueprint $table): void {
            $table->dropIndex('aca_inspection_idx');
            $table->dropColumn([
                'inspection_status', 'inspection_driver', 'inspection_version',
                'inspection_safe_code', 'inspected_at_utc',
            ]);
        });
        Schema::table('organization_ai_policies', function (Blueprint $table): void {
            $table->dropColumn('allows_transcription');
        });
    }
};
