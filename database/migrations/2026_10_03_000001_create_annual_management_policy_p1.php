<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_management_periods', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained(indexName: 'omp_org_fk')->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', indexName: 'omp_created_user_fk')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users', indexName: 'omp_updated_user_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'name'], 'org_mgmt_periods_org_name_unique');
            $table->index(['organization_id', 'starts_on', 'ends_on'], 'org_mgmt_periods_dates_idx');
        });

        Schema::create('organization_management_period_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_management_period_id')->constrained('organization_management_periods', indexName: 'ompv_period_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('version_no');
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('actor_user_id')->nullable()->constrained('users', indexName: 'ompv_actor_fk')->nullOnDelete();
            $table->string('change_reason', 2000)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->unique(['organization_management_period_id', 'version_no'], 'org_mgmt_period_versions_unique');
        });

        Schema::create('annual_management_policies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained(indexName: 'amp_org_fk')->cascadeOnDelete();
            $table->foreignId('organization_management_period_id')->constrained('organization_management_periods', indexName: 'amp_period_fk')->restrictOnDelete();
            $table->unsignedBigInteger('draft_version')->default(0);
            $table->unsignedBigInteger('base_approved_revision_no')->nullable();
            $table->unsignedBigInteger('current_approved_revision_id')->nullable();
            $table->string('draft_period_name', 120)->nullable();
            $table->date('draft_starts_on')->nullable();
            $table->date('draft_ends_on')->nullable();
            $table->longText('draft_purpose')->nullable();
            $table->longText('draft_background')->nullable();
            $table->longText('draft_policy')->nullable();
            $table->string('approved_view_scope', 32)->default('explicit');
            $table->unsignedBigInteger('relation_version')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', indexName: 'amp_created_user_fk')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users', indexName: 'amp_updated_user_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'organization_management_period_id'], 'annual_policies_org_period_unique');
            $table->index(['organization_id', 'current_approved_revision_id'], 'annual_policies_current_idx');
        });

        Schema::create('annual_management_policy_themes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('annual_management_policy_id')->constrained('annual_management_policies', indexName: 'ampt_policy_fk')->cascadeOnDelete();
            $table->longText('statement')->nullable();
            $table->longText('explanation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['annual_management_policy_id', 'sort_order'], 'annual_policy_themes_order_idx');
        });

        Schema::create('annual_management_policy_priorities', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('annual_management_policy_id')->constrained('annual_management_policies', indexName: 'ampp_policy_fk')->cascadeOnDelete();
            $table->foreignId('annual_management_policy_theme_id')->constrained('annual_management_policy_themes', indexName: 'ampp_theme_fk')->cascadeOnDelete();
            $table->longText('statement')->nullable();
            $table->longText('explanation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['annual_management_policy_theme_id', 'sort_order'], 'annual_policy_priorities_order_idx');
        });

        Schema::create('annual_management_policy_departments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('annual_management_policy_id')->constrained('annual_management_policies', indexName: 'ampd_policy_fk')->cascadeOnDelete();
            $table->foreignId('organization_group_id')->constrained('organization_groups', indexName: 'ampd_group_fk')->restrictOnDelete();
            $table->longText('introduction')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['annual_management_policy_id', 'organization_group_id'], 'annual_policy_department_group_unique');
            $table->index(['annual_management_policy_id', 'sort_order'], 'annual_policy_departments_order_idx');
        });

        Schema::create('annual_management_policy_department_statements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('annual_management_policy_department_id')->constrained('annual_management_policy_departments', indexName: 'ampds_department_fk')->cascadeOnDelete();
            $table->longText('statement')->nullable();
            $table->longText('explanation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['annual_management_policy_department_id', 'sort_order'], 'annual_policy_department_statements_order_idx');
        });

        Schema::create('annual_management_policy_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('annual_management_policy_id')->constrained('annual_management_policies', indexName: 'ampg_policy_fk')->cascadeOnDelete();
            $table->foreignId('organization_user_id')->constrained('organization_users', indexName: 'ampg_org_user_fk')->cascadeOnDelete();
            $table->boolean('can_view_approved')->default(false);
            $table->boolean('can_view_draft')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users', indexName: 'ampg_updated_user_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['annual_management_policy_id', 'organization_user_id'], 'annual_policy_grants_unique');
            $table->index(['annual_management_policy_id', 'can_view_approved'], 'annual_policy_grants_approved_idx');
        });

        Schema::create('annual_management_policy_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained(indexName: 'ampo_org_fk')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', indexName: 'ampo_actor_fk')->cascadeOnDelete();
            $table->string('request_id', 64);
            $table->char('payload_hash', 64);
            $table->string('operation', 48);
            $table->foreignId('annual_management_policy_id')->nullable()->constrained('annual_management_policies', indexName: 'ampo_policy_fk')->nullOnDelete();
            $table->unsignedBigInteger('result_revision_no')->nullable();
            $table->json('result_metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'actor_user_id', 'request_id'], 'annual_policy_operations_request_unique');
        });

        Schema::create('annual_management_policy_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('annual_management_policy_id')->constrained('annual_management_policies', indexName: 'ampr_policy_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('revision_no');
            $table->unsignedSmallInteger('snapshot_schema_version')->default(1);
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users', indexName: 'ampr_approved_user_fk')->nullOnDelete();
            $table->foreignId('annual_management_policy_operation_id')->nullable()->constrained('annual_management_policy_operations', indexName: 'ampr_operation_fk')->nullOnDelete();
            $table->string('change_reason', 2000)->nullable();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['annual_management_policy_id', 'revision_no'], 'annual_policy_revisions_unique');
            $table->index(['annual_management_policy_id', 'approved_at'], 'annual_policy_revisions_time_idx');
        });

        Schema::table('annual_management_policies', function (Blueprint $table): void {
            $table->foreign('current_approved_revision_id', 'amp_current_revision_fk')
                ->references('id')->on('annual_management_policy_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('annual_management_policies', function (Blueprint $table): void {
            $table->dropForeign('amp_current_revision_fk');
        });
        Schema::dropIfExists('annual_management_policy_revisions');
        Schema::dropIfExists('annual_management_policy_operations');
        Schema::dropIfExists('annual_management_policy_grants');
        Schema::dropIfExists('annual_management_policy_department_statements');
        Schema::dropIfExists('annual_management_policy_departments');
        Schema::dropIfExists('annual_management_policy_priorities');
        Schema::dropIfExists('annual_management_policy_themes');
        Schema::dropIfExists('annual_management_policies');
        Schema::dropIfExists('organization_management_period_versions');
        Schema::dropIfExists('organization_management_periods');
    }
};
