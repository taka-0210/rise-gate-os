<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_common_conversations', function (Blueprint $table): void {
            $table->string('conversation_kind', 16)->default('private')->after('project_id');
            $table->index(
                ['organization_id', 'conversation_kind', 'status'],
                'ai_common_conversation_kind_index'
            );
        });

        Schema::create('ai_common_shared_conversations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_conversation_id')->unique();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('pending_owner_user_id')->nullable();
            $table->unsignedBigInteger('participant_version')->default(1);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('organization_id', 'acsc_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_conversation_id', 'acsc_conversation_fk')->references('id')->on('ai_common_conversations')->restrictOnDelete();
            $table->foreign('owner_user_id', 'acsc_owner_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('pending_owner_user_id', 'acsc_pending_owner_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['organization_id', 'owner_user_id'], 'acsc_org_owner_index');
            $table->index(['pending_owner_user_id'], 'acsc_pending_owner_index');
        });

        Schema::create('ai_common_shared_purpose_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('revision_no');
            $table->text('purpose');
            $table->char('purpose_hash', 64);
            $table->unsignedBigInteger('created_by_user_id');
            $table->uuid('operation_id');
            $table->timestamps();

            $table->foreign('ai_common_shared_conversation_id', 'acsp_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'acsp_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['ai_common_shared_conversation_id', 'revision_no'],
                'acsp_revision_unique'
            );
            $table->unique(
                ['ai_common_shared_conversation_id', 'operation_id'],
                'acsp_operation_unique'
            );
        });

        Schema::table('ai_common_shared_conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_purpose_revision_id')->nullable()->after('pending_owner_user_id');
            $table->foreign('current_purpose_revision_id', 'acsc_purpose_fk')
                ->references('id')->on('ai_common_shared_purpose_revisions')->restrictOnDelete();
        });

        Schema::create('ai_common_shared_participants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('invitation_public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 20)->default('participant');
            $table->string('status', 20)->default('invited');
            $table->unsignedBigInteger('invited_by_user_id');
            $table->unsignedBigInteger('audience_epoch')->default(1);
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('accepted_membership_epoch')->nullable();
            $table->unsignedBigInteger('accepted_credential_generation')->nullable();
            $table->timestamp('invited_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('ai_common_shared_conversation_id', 'acspa_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('user_id', 'acspa_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('invited_by_user_id', 'acspa_inviter_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('removed_by_user_id', 'acspa_remover_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['ai_common_shared_conversation_id', 'user_id'],
                'acspa_conversation_user_unique'
            );
            $table->index(
                ['user_id', 'status', 'ai_common_shared_conversation_id'],
                'acspa_user_status_index'
            );
            $table->index(
                ['ai_common_shared_conversation_id', 'status'],
                'acspa_conversation_status_index'
            );
        });

        Schema::create('ai_common_shared_message_authors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_message_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_conversation_id');
            $table->unsignedBigInteger('ai_common_shared_participant_id');
            $table->unsignedBigInteger('author_user_id');
            $table->unsignedBigInteger('participant_audience_epoch');
            $table->unsignedBigInteger('membership_access_epoch');
            $table->unsignedBigInteger('credential_generation');
            $table->timestamps();

            $table->foreign('ai_common_message_id', 'acsma_message_fk')->references('id')->on('ai_common_messages')->restrictOnDelete();
            $table->foreign('ai_common_shared_conversation_id', 'acsma_conversation_fk')->references('id')->on('ai_common_shared_conversations')->restrictOnDelete();
            $table->foreign('ai_common_shared_participant_id', 'acsma_participant_fk')->references('id')->on('ai_common_shared_participants')->restrictOnDelete();
            $table->foreign('author_user_id', 'acsma_author_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(
                ['ai_common_shared_conversation_id', 'author_user_id'],
                'acsma_conversation_author_index'
            );
        });

        Schema::create('ai_common_shared_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_conversation_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('operation_id');
            $table->string('command', 40);
            $table->char('payload_fingerprint', 64);
            $table->string('result_type', 40);
            $table->unsignedBigInteger('result_id');
            $table->string('result_status', 24)->default('completed');
            $table->timestamps();

            $table->foreign('organization_id', 'acso_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_conversation_id', 'acso_conversation_fk')->references('id')->on('ai_common_conversations')->restrictOnDelete();
            $table->foreign('actor_user_id', 'acso_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['organization_id', 'actor_user_id', 'operation_id'],
                'acso_actor_operation_unique'
            );
            $table->index(
                ['ai_common_conversation_id', 'command'],
                'acso_conversation_command_index'
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_common_shared_conversations')
            && (DB::table('ai_common_shared_conversations')->exists()
                || DB::table('ai_common_shared_operations')->exists())) {
            throw new RuntimeException(
                'Shared Conversation history exists. Disable the feature and preserve history; destructive rollback is not supported.'
            );
        }

        Schema::dropIfExists('ai_common_shared_operations');
        Schema::dropIfExists('ai_common_shared_message_authors');
        Schema::dropIfExists('ai_common_shared_participants');
        Schema::table('ai_common_shared_conversations', function (Blueprint $table): void {
            $table->dropForeign('acsc_purpose_fk');
            $table->dropColumn('current_purpose_revision_id');
        });
        Schema::dropIfExists('ai_common_shared_purpose_revisions');
        Schema::dropIfExists('ai_common_shared_conversations');
        Schema::table('ai_common_conversations', function (Blueprint $table): void {
            $table->dropIndex('ai_common_conversation_kind_index');
            $table->dropColumn('conversation_kind');
        });
    }
};
