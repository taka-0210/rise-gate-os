<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_design_access_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->string('view_scope', 32)->default('explicit');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'item_type'], 'md_access_settings_org_type_unique');
        });

        Schema::create('management_design_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('organization_user_id')->constrained('organization_users')->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'item_type', 'organization_user_id'],
                'md_grants_org_type_membership_unique',
            );
            $table->index(['organization_id', 'item_type', 'can_view'], 'md_grants_view_idx');
            $table->index(['organization_id', 'item_type', 'can_edit'], 'md_grants_edit_idx');
        });

        Schema::create('management_design_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->longText('statement')->nullable();
            $table->string('horizon', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'type'], 'md_items_org_type_unique');
            $table->index(['organization_id', 'status'], 'md_items_org_status_idx');
            $table->index('current_revision_id', 'md_items_current_revision_idx');
        });

        Schema::create('management_design_sections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('management_design_item_id')->constrained('management_design_items')->cascadeOnDelete();
            $table->string('title');
            $table->longText('body');
            $table->string('horizon', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['management_design_item_id', 'status', 'sort_order'],
                'md_sections_item_status_order_idx',
            );
        });

        Schema::create('management_design_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_id', 64);
            $table->char('payload_hash', 64);
            $table->string('operation', 40);
            $table->foreignId('management_design_item_id')->nullable()->constrained('management_design_items')->nullOnDelete();
            $table->unsignedBigInteger('result_revision_no')->nullable();
            $table->json('result_metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'actor_user_id', 'request_id'], 'md_operations_request_unique');
        });

        Schema::create('management_design_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('management_design_item_id')->constrained('management_design_items')->cascadeOnDelete();
            $table->unsignedBigInteger('revision_no');
            $table->unsignedSmallInteger('snapshot_schema_version')->default(1);
            $table->string('status', 20);
            $table->json('snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('management_design_operation_id')->nullable()->constrained('management_design_operations')->nullOnDelete();
            $table->string('change_reason', 2000)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->unique(
                ['management_design_item_id', 'revision_no'],
                'md_revisions_item_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_design_revisions');
        Schema::dropIfExists('management_design_operations');
        Schema::dropIfExists('management_design_sections');
        Schema::dropIfExists('management_design_items');
        Schema::dropIfExists('management_design_grants');
        Schema::dropIfExists('management_design_access_settings');
    }
};
