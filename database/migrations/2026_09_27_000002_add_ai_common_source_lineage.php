<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_common_source_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_source_id');
            $table->unsignedBigInteger('ai_common_conversation_id');
            $table->unsignedBigInteger('selected_by_user_id')->nullable();
            $table->foreign('ai_common_source_id', 'ai_src_rev_source_fk')
                ->references('id')->on('ai_common_sources')->cascadeOnDelete();
            $table->foreign('ai_common_conversation_id', 'ai_src_rev_conversation_fk')
                ->references('id')->on('ai_common_conversations')->cascadeOnDelete();
            $table->foreign('selected_by_user_id', 'ai_src_rev_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->string('opaque_handle', 96)->unique();
            $table->string('resource_type', 40);
            $table->string('resource_public_id', 64);
            $table->string('resource_version', 64);
            $table->json('selector');
            $table->json('projection');
            $table->unsignedBigInteger('organization_policy_version');
            $table->unsignedBigInteger('resource_policy_version');
            $table->unsignedBigInteger('membership_access_epoch');
            $table->unsignedBigInteger('credential_generation');
            $table->string('access_fingerprint', 64);
            $table->string('selection_reason', 160);
            $table->timestamp('selected_at');
            $table->timestamps();

            $table->index(['ai_common_conversation_id', 'id'], 'ai_common_revision_conversation_index');
            $table->index(['resource_type', 'resource_public_id'], 'ai_common_revision_resource_index');
        });

        Schema::table('ai_common_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_revision_id')->nullable()->after('ai_common_conversation_id');
            $table->foreign('current_revision_id', 'ai_source_current_revision_fk')
                ->references('id')->on('ai_common_source_revisions')->nullOnDelete();
        });

        Schema::table('ai_common_messages', function (Blueprint $table): void {
            $table->string('source_lineage_version', 40)->nullable()->after('source_fingerprint');
            $table->index(['ai_common_conversation_id', 'source_lineage_version'], 'ai_common_message_lineage_index');
        });

        Schema::create('ai_common_message_source_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_common_message_id');
            $table->unsignedBigInteger('ai_common_source_revision_id');
            $table->foreign('ai_common_message_id', 'ai_msg_rev_message_fk')
                ->references('id')->on('ai_common_messages')->cascadeOnDelete();
            $table->foreign('ai_common_source_revision_id', 'ai_msg_rev_revision_fk')
                ->references('id')->on('ai_common_source_revisions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['ai_common_message_id', 'ai_common_source_revision_id'],
                'ai_common_message_revision_unique'
            );
        });

        Schema::table('ai_common_handoff_relations', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_message_id')->nullable()->after('ai_proposal_id');
            $table->foreign('source_message_id', 'ai_handoff_source_message_fk')
                ->references('id')->on('ai_common_messages')->nullOnDelete();
            $table->string('source_lineage_version', 40)->nullable()->after('source_message_id');
        });

        Schema::create('ai_common_proposal_source_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_proposal_id');
            $table->unsignedBigInteger('ai_common_source_revision_id');
            $table->foreign('ai_proposal_id', 'ai_proposal_rev_proposal_fk')
                ->references('id')->on('ai_proposals')->cascadeOnDelete();
            $table->foreign('ai_common_source_revision_id', 'ai_proposal_rev_revision_fk')
                ->references('id')->on('ai_common_source_revisions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['ai_proposal_id', 'ai_common_source_revision_id'],
                'ai_common_proposal_revision_unique'
            );
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('ai_common_source_revisions') && DB::table('ai_common_source_revisions')->exists())
            || (Schema::hasTable('ai_common_message_source_revisions') && DB::table('ai_common_message_source_revisions')->exists())
            || (Schema::hasTable('ai_common_proposal_source_revisions') && DB::table('ai_common_proposal_source_revisions')->exists())) {
            throw new RuntimeException('AI source lineage history exists. Preserve immutable revisions; destructive rollback is not supported.');
        }

        Schema::dropIfExists('ai_common_proposal_source_revisions');
        Schema::table('ai_common_handoff_relations', function (Blueprint $table): void {
            $table->dropForeign('ai_handoff_source_message_fk');
            $table->dropColumn('source_message_id');
            $table->dropColumn('source_lineage_version');
        });
        Schema::dropIfExists('ai_common_message_source_revisions');
        Schema::table('ai_common_messages', function (Blueprint $table): void {
            $table->dropIndex('ai_common_message_lineage_index');
            $table->dropColumn('source_lineage_version');
        });
        Schema::table('ai_common_sources', function (Blueprint $table): void {
            $table->dropForeign('ai_source_current_revision_fk');
            $table->dropColumn('current_revision_id');
        });
        Schema::dropIfExists('ai_common_source_revisions');
    }
};
