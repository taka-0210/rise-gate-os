<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'personal_workspace_creation_enabled')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->boolean('personal_workspace_creation_enabled')->default(true)->after('standard_workspace_id');
            });
        }

        if (! Schema::hasTable('owner_onboardings')) {
            Schema::create('owner_onboardings', function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('normalized_email');
                $table->string('organization_name');
                $table->string('normalized_organization_name');
                $table->char('pending_case_key', 64)->nullable()->unique();
                $table->string('duplicate_decision', 30)->default('no_match');
                $table->string('distinct_company_reason', 500)->nullable();
                $table->string('status', 20)->default('issued');
                $table->char('token_hash', 64);
                $table->unsignedBigInteger('token_generation')->default(1);
                $table->timestamp('expires_at');
                $table->foreignId('claimed_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('completed_organization_id')->nullable()->unique()->constrained('organizations')->nullOnDelete();
                $table->char('completed_payload_hash', 64)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->string('delivery_status', 20)->default('queued');
                $table->timestamp('delivery_requested_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('delivery_failed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'expires_at']);
                $table->index(['normalized_organization_name', 'status'], 'owner_onboarding_company_status_idx');
                $table->index(['normalized_email', 'status'], 'owner_onboarding_email_status_idx');
            });
        }

        if (! Schema::hasTable('owner_onboarding_operations')) {
            Schema::create('owner_onboarding_operations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_onboarding_id')->constrained('owner_onboardings')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('operation', 20);
                $table->string('request_id', 64);
                $table->char('payload_hash', 64);
                $table->timestamps();

                $table->unique(['operation', 'request_id'], 'owner_onboarding_operations_request_unique');
            });
        }

        if (! Schema::hasTable('owner_onboarding_audit_events')) {
            Schema::create('owner_onboarding_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_onboarding_id')->constrained('owner_onboardings')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 80);
                $table->string('outcome', 20);
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['owner_onboarding_id', 'occurred_at'], 'owner_onboarding_audit_case_idx');
            });
        }

        if (! Schema::hasTable('user_legal_consents')) {
            Schema::create('user_legal_consents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('owner_onboarding_id')->constrained('owner_onboardings')->cascadeOnDelete();
                $table->string('purpose', 40);
                $table->string('terms_version', 100);
                $table->char('terms_content_hash', 64);
                $table->string('privacy_version', 100);
                $table->char('privacy_content_hash', 64);
                $table->char('document_signature', 64);
                $table->string('recorded_timezone', 50)->default('Asia/Tokyo');
                $table->timestamp('consented_at');
                $table->timestamps();

                $table->unique(
                    ['user_id', 'owner_onboarding_id', 'purpose', 'document_signature'],
                    'user_legal_consents_owner_onboarding_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_legal_consents');
        Schema::dropIfExists('owner_onboarding_audit_events');
        Schema::dropIfExists('owner_onboarding_operations');
        Schema::dropIfExists('owner_onboardings');

        if (Schema::hasColumn('organizations', 'personal_workspace_creation_enabled')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn('personal_workspace_creation_enabled');
            });
        }
    }
};
