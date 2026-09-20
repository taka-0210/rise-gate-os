<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_domains', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->longText('description')->nullable();
            $table->longText('what_summary')->nullable();
            $table->longText('who_summary')->nullable();
            $table->longText('value_proposition')->nullable();
            $table->longText('geographic_scope_summary')->nullable();
            $table->longText('market_position_summary')->nullable();
            $table->longText('self_recognized_strengths')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'name']);
        });

        Schema::create('business_domain_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_domain_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('name');
            $table->longText('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_domain_id', 'status', 'sort_order'], 'business_domain_items_list_idx');
        });

        Schema::create('business_domain_item_attributes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_domain_item_id')->constrained()->cascadeOnDelete();
            $table->string('axis', 30);
            $table->string('label');
            $table->longText('value_text');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_domain_item_id', 'status', 'sort_order'], 'business_domain_attributes_list_idx');
        });

        Schema::create('business_domain_editor_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_user_id')->constrained('organization_users')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'organization_user_id'], 'business_domain_editor_grants_membership_unique');
        });

        Schema::create('business_domain_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_id', 64);
            $table->char('payload_hash', 64);
            $table->string('operation', 30);
            $table->foreignId('business_domain_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('result_revision_no')->nullable();
            $table->json('result_metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'actor_user_id', 'request_id'], 'business_domain_operations_request_unique');
        });

        Schema::create('business_domain_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('revision_no');
            $table->unsignedSmallInteger('snapshot_schema_version')->default(1);
            $table->json('snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('business_domain_operation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('change_reason', 2000)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->unique(['business_domain_id', 'revision_no'], 'business_domain_revisions_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_domain_revisions');
        Schema::dropIfExists('business_domain_operations');
        Schema::dropIfExists('business_domain_editor_grants');
        Schema::dropIfExists('business_domain_item_attributes');
        Schema::dropIfExists('business_domain_items');
        Schema::dropIfExists('business_domains');
    }
};
