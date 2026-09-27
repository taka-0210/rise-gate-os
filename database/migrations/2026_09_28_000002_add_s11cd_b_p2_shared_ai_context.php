<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_common_shared_source_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_source_revision_id');
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('selected_by_participant_id');
            $table->unsignedBigInteger('participant_version');
            $table->char('audience_fingerprint', 64);
            $table->json('audience_snapshot');
            $table->timestamps();
            $table->foreign('ai_common_source_revision_id', 'acssc_revision_fk')->references('id')->on('ai_common_source_revisions')->restrictOnDelete();
            $table->foreign('ai_common_shared_conversation_id', 'acssc_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('selected_by_participant_id', 'acssc_participant_fk')->references('id')->on('ai_common_shared_participants')->restrictOnDelete();
            $table->unique('ai_common_source_revision_id', 'acssc_revision_unique');
            $table->index(['ai_common_shared_conversation_id', 'participant_version'], 'acssc_conversation_version_index');
        });

        Schema::create('ai_common_shared_ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('actor_participant_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('purpose_revision_id');
            $table->unsignedBigInteger('request_message_id')->nullable();
            $table->unsignedBigInteger('response_message_id')->nullable();
            $table->uuid('operation_id');
            $table->uuid('logical_request_id');
            $table->char('payload_fingerprint', 64);
            $table->char('audience_fingerprint', 64);
            $table->string('state', 24);
            $table->string('phase', 32);
            $table->unsignedBigInteger('sequence');
            $table->string('safe_error_code', 80)->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_conversation_id', 'acsr_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('actor_participant_id', 'acsr_participant_fk')->references('id')->on('ai_common_shared_participants')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acsr_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('purpose_revision_id', 'acsr_purpose_fk')->references('id')->on('ai_common_shared_purpose_revisions')->restrictOnDelete();
            $table->foreign('request_message_id', 'acsr_request_message_fk')->references('id')->on('ai_common_messages')->restrictOnDelete();
            $table->foreign('response_message_id', 'acsr_response_message_fk')->references('id')->on('ai_common_messages')->restrictOnDelete();
            $table->unique(['ai_common_shared_conversation_id', 'operation_id'], 'acsr_conversation_operation_unique');
            $table->unique(['ai_common_shared_conversation_id', 'logical_request_id'], 'acsr_conversation_request_unique');
            $table->index(['ai_common_shared_conversation_id', 'state'], 'acsr_conversation_state_index');
        });

        Schema::create('ai_common_shared_co_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('current_request_id')->nullable();
            $table->unsignedBigInteger('current_response_message_id')->nullable();
            $table->string('state', 24)->default('idle');
            $table->string('phase', 32)->default('idle');
            $table->unsignedBigInteger('sequence')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->foreign('ai_common_shared_conversation_id', 'acss_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('current_request_id', 'acss_request_fk')->references('id')->on('ai_common_shared_ai_requests')->restrictOnDelete();
            $table->foreign('current_response_message_id', 'acss_response_fk')->references('id')->on('ai_common_messages')->restrictOnDelete();
            $table->unique('ai_common_shared_conversation_id', 'acss_conversation_unique');
        });

        Schema::create('ai_common_shared_proposal_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_proposal_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('created_by_participant_id');
            $table->unsignedBigInteger('approval_recipient_user_id');
            $table->unsignedBigInteger('participant_version');
            $table->char('audience_fingerprint', 64);
            $table->char('target_snapshot_fingerprint', 64);
            $table->json('audience_snapshot');
            $table->timestamps();
            $table->foreign('ai_proposal_id', 'acspc_proposal_fk')->references('id')->on('ai_proposals')->restrictOnDelete();
            $table->foreign('ai_common_shared_conversation_id', 'acspc_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('created_by_participant_id', 'acspc_participant_fk')->references('id')->on('ai_common_shared_participants')->restrictOnDelete();
            $table->foreign('approval_recipient_user_id', 'acspc_recipient_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['ai_common_shared_conversation_id', 'approval_recipient_user_id'], 'acspc_conversation_recipient_index');
        });
    }

    public function down(): void
    {
        if (DB::table('ai_common_shared_ai_requests')->exists()
            || DB::table('ai_common_shared_source_contexts')->exists()
            || DB::table('ai_common_shared_proposal_contexts')->exists()) {
            throw new RuntimeException('Shared AI P2 history exists; destructive rollback is not supported.');
        }
        Schema::dropIfExists('ai_common_shared_co_states');
        Schema::dropIfExists('ai_common_shared_proposal_contexts');
        Schema::dropIfExists('ai_common_shared_ai_requests');
        Schema::dropIfExists('ai_common_shared_source_contexts');
    }
};
