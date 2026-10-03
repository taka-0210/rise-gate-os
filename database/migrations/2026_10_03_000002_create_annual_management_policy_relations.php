<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_management_policy_relations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained(indexName: 'amprel_org_fk')->cascadeOnDelete();
            $table->foreignId('annual_management_policy_id')->constrained('annual_management_policies', indexName: 'amprel_policy_fk')->cascadeOnDelete();
            $table->string('source_type', 24);
            $table->string('source_public_id', 26);
            $table->string('target_type', 24);
            $table->string('target_public_id', 26);
            $table->unsignedBigInteger('current_version')->default(0);
            $table->string('current_status', 20)->default('withdrawn');
            $table->timestamps();

            $table->unique(
                ['annual_management_policy_id', 'source_type', 'source_public_id', 'target_type', 'target_public_id'],
                'annual_policy_relations_edge_unique',
            );
            $table->index(['annual_management_policy_id', 'current_status'], 'annual_policy_relations_status_idx');
        });

        Schema::create('annual_management_policy_relation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('annual_management_policy_relation_id')->constrained('annual_management_policy_relations', indexName: 'amprelv_relation_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('version_no');
            $table->string('status', 20);
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users', indexName: 'amprelv_user_fk')->nullOnDelete();
            $table->string('reason', 2000)->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['annual_management_policy_relation_id', 'version_no'], 'annual_policy_relation_versions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_management_policy_relation_versions');
        Schema::dropIfExists('annual_management_policy_relations');
    }
};
