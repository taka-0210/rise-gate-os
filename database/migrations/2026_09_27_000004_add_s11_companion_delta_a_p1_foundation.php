<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_common_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_conversation_id');
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->string('variant', 24);
            $table->string('state', 24)->default('receiving');
            $table->unsignedBigInteger('version')->default(1);
            $table->string('display_name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('storage_key', 500)->nullable();
            $table->string('origin_type', 64)->nullable();
            $table->string('origin_public_id', 64)->nullable();
            $table->char('origin_sha256', 64)->nullable();
            $table->boolean('allows_ai_reference')->default(false);
            $table->unsignedBigInteger('ai_reference_version')->default(1);
            $table->unsignedBigInteger('uploader_access_epoch');
            $table->unsignedBigInteger('uploader_credential_generation');
            $table->timestamp('ready_at_utc')->nullable();
            $table->timestamp('revoked_at_utc')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'aca_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_conversation_id', 'aca_conversation_fk')->references('id')->on('ai_common_conversations')->restrictOnDelete();
            $table->foreign('uploaded_by_user_id', 'aca_uploader_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by_user_id', 'aca_revoker_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['organization_id', 'ai_common_conversation_id', 'state', 'id'], 'aca_conversation_state_idx');
            $table->index(['origin_type', 'origin_public_id'], 'aca_origin_idx');
        });

        Schema::create('ai_common_attachment_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_conversation_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('ai_common_attachment_id')->nullable();
            $table->uuid('operation_id');
            $table->string('command', 40);
            $table->char('payload_fingerprint', 64);
            $table->string('result_status', 24);
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'acao_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_conversation_id', 'acao_conversation_fk')->references('id')->on('ai_common_conversations')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acao_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ai_common_attachment_id', 'acao_attachment_fk')->references('id')->on('ai_common_attachments')->restrictOnDelete();
            $table->unique(['ai_common_conversation_id', 'actor_user_id', 'operation_id'], 'acao_actor_operation_uq');
            $table->index(['result_status', 'created_at'], 'acao_result_idx');
        });

        Schema::create('ai_common_input_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_conversation_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('ai_common_message_id')->nullable();
            $table->uuid('operation_id');
            $table->string('command', 40);
            $table->char('payload_fingerprint', 64);
            $table->string('result_status', 24);
            $table->timestamps();

            $table->foreign('organization_id', 'acio_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_conversation_id', 'acio_conversation_fk')->references('id')->on('ai_common_conversations')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acio_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ai_common_message_id', 'acio_message_fk')->references('id')->on('ai_common_messages')->restrictOnDelete();
            $table->unique(['ai_common_conversation_id', 'actor_user_id', 'operation_id'], 'acio_actor_operation_uq');
        });

        Schema::create('ai_common_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_message_id');
            $table->unsignedBigInteger('ai_common_attachment_id');
            $table->unsignedBigInteger('attachment_version');
            $table->timestamps();

            $table->foreign('ai_common_message_id', 'acma_message_fk')->references('id')->on('ai_common_messages')->restrictOnDelete();
            $table->foreign('ai_common_attachment_id', 'acma_attachment_fk')->references('id')->on('ai_common_attachments')->restrictOnDelete();
            $table->unique(['ai_common_message_id', 'ai_common_attachment_id'], 'acma_message_attachment_uq');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_common_attachments')
            && (DB::table('ai_common_attachments')->exists()
                || DB::table('ai_common_attachment_operations')->exists()
                || DB::table('ai_common_input_operations')->exists())) {
            throw new RuntimeException(
                'Delta A attachment or input history exists. Disable the feature and preserve history; destructive rollback is not supported.'
            );
        }

        Schema::dropIfExists('ai_common_message_attachments');
        Schema::dropIfExists('ai_common_input_operations');
        Schema::dropIfExists('ai_common_attachment_operations');
        Schema::dropIfExists('ai_common_attachments');
    }
};
