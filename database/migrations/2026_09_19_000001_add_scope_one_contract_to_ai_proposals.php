<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'roadmaps', 'improvements', 'tasks'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('plan_version')->default(1);
            });
        }

        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->string('contract_version')->nullable()->after('mode');
            $table->string('capability')->nullable()->after('contract_version');
            $table->string('risk_level')->nullable()->after('capability');
            $table->string('content_hash', 64)->nullable()->after('risk_level');
            $table->unsignedBigInteger('expected_project_version')->nullable()->after('content_hash');
            $table->string('approval_policy')->nullable()->after('expected_project_version');
            $table->string('approved_content_hash', 64)->nullable()->after('approval_policy');
            $table->unsignedBigInteger('approved_project_version')->nullable()->after('approved_content_hash');
            $table->foreignId('approved_by')->nullable()->after('approved_project_version')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::table('ai_proposal_items', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
            $table->json('depends_on')->nullable()->after('parent_reference');
            $table->json('before')->nullable()->after('attributes');
            $table->json('after')->nullable()->after('before');
            $table->unsignedBigInteger('expected_version')->nullable()->after('after');
            $table->string('applied_entity_public_id')->nullable()->after('validation_message');
            $table->unsignedBigInteger('applied_version')->nullable()->after('applied_entity_public_id');
            $table->unique('public_id', 'ai_proposal_items_public_id_unique');
        });

        Schema::create('ai_proposal_apply_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key');
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->boolean('retryable')->default(false);
            $table->text('error_message')->nullable();
            $table->unsignedInteger('applied_items_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_proposal_id', 'idempotency_key']);
            $table->unique(['ai_proposal_id', 'attempt_number']);
        });

        Schema::create('ai_proposal_item_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_proposal_apply_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_proposal_item_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('applied_entity_public_id')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['ai_proposal_apply_attempt_id', 'ai_proposal_item_id'], 'proposal_attempt_item_unique');
        });

        Schema::create('ai_proposal_undos', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->json('result')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposal_undos');
        Schema::dropIfExists('ai_proposal_item_results');
        Schema::dropIfExists('ai_proposal_apply_attempts');

        Schema::table('ai_proposal_items', function (Blueprint $table): void {
            $table->dropUnique('ai_proposal_items_public_id_unique');
            $table->dropColumn(['public_id', 'depends_on', 'before', 'after', 'expected_version', 'applied_entity_public_id', 'applied_version']);
        });
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'contract_version', 'capability', 'risk_level', 'content_hash',
                'expected_project_version', 'approval_policy', 'approved_content_hash',
                'approved_project_version', 'approved_at',
            ]);
        });
        foreach (['tasks', 'improvements', 'roadmaps', 'projects'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('plan_version'));
        }
    }
};
