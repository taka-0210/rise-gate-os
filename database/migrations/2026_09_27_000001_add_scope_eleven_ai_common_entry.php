<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_ai_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->json('allowed_categories')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('managed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_resource_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 40);
            $table->string('resource_public_id', 64);
            $table->boolean('allows_ai_reference')->default(false);
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('managed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'resource_type', 'resource_public_id'], 'ai_resource_policy_unique');
            $table->index(['organization_id', 'allows_ai_reference']);
        });

        Schema::create('ai_common_conversations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'status'], 'ai_common_conversation_private_index');
        });

        Schema::create('ai_common_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_common_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('visibility_status', 24)->default('visible');
            $table->string('source_fingerprint', 64)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('logical_request_id', 64)->nullable();
            $table->timestamps();

            $table->index(['ai_common_conversation_id', 'created_at'], 'ai_common_message_timeline_index');
            $table->index('logical_request_id');
        });

        Schema::create('ai_common_sources', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_common_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('selected_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('opaque_handle', 96)->unique();
            $table->string('resource_type', 40);
            $table->string('resource_public_id', 64);
            $table->string('resource_version', 64);
            $table->string('freshness_fingerprint', 64);
            $table->string('selection_reason', 160);
            $table->json('projection');
            $table->timestamp('selected_at');
            $table->timestamps();

            $table->unique(
                ['ai_common_conversation_id', 'resource_type', 'resource_public_id'],
                'ai_common_source_selection_unique'
            );
            $table->index(['resource_type', 'resource_public_id']);
        });

        Schema::create('ai_common_message_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_common_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_common_source_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ai_common_message_id', 'ai_common_source_id'], 'ai_common_message_source_unique');
        });

        Schema::create('ai_usage_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_common_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('logical_request_id', 64);
            $table->string('purpose', 40);
            $table->string('provider', 40);
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('attempt');
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('estimated_cost_microunits')->nullable();
            $table->string('price_version', 40)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('result', 24);
            $table->string('safe_error_code', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['logical_request_id', 'provider', 'attempt'], 'ai_usage_request_attempt_unique');
            $table->index(['organization_id', 'user_id', 'created_at'], 'ai_usage_actor_index');
            $table->index(['purpose', 'result']);
        });

        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->change();
            $table->foreignId('project_id')->nullable()->change();
            $table->foreignId('ai_common_conversation_id')->nullable()->after('project_id')
                ->constrained('ai_common_conversations')->nullOnDelete();
            $table->string('scope_key', 160)->nullable()->after('ai_common_conversation_id');
            $table->string('target_type', 40)->nullable()->after('scope_key');
            $table->string('target_public_id', 64)->nullable()->after('target_type');
            $table->unsignedBigInteger('expected_target_version')->nullable()->after('target_public_id');
            $table->string('common_operation_key', 64)->nullable()->after('idempotency_key')->unique();
            $table->index(['organization_id', 'scope_key', 'status'], 'ai_proposal_common_scope_index');
        });

        Schema::create('ai_common_handoff_relations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_common_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_proposal_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('target_type', 40)->nullable();
            $table->string('target_public_id', 64)->nullable();
            $table->text('published_summary')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_public_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_common_conversations')
            && (DB::table('ai_common_conversations')->exists() || DB::table('ai_proposals')->whereNotNull('ai_common_conversation_id')->exists())) {
            throw new \RuntimeException('Scope 11 history exists. Disable the feature and preserve history; destructive rollback is not supported.');
        }

        Schema::dropIfExists('ai_common_handoff_relations');
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->dropIndex('ai_proposal_common_scope_index');
            $table->dropUnique(['common_operation_key']);
            $table->dropConstrainedForeignId('ai_common_conversation_id');
            $table->dropColumn(['scope_key', 'target_type', 'target_public_id', 'expected_target_version', 'common_operation_key']);
            $table->foreignId('workspace_id')->nullable(false)->change();
            $table->foreignId('project_id')->nullable(false)->change();
        });
        Schema::dropIfExists('ai_usage_ledgers');
        Schema::dropIfExists('ai_common_message_sources');
        Schema::dropIfExists('ai_common_sources');
        Schema::dropIfExists('ai_common_messages');
        Schema::dropIfExists('ai_common_conversations');
        Schema::dropIfExists('ai_resource_policies');
        Schema::dropIfExists('organization_ai_policies');
    }
};
