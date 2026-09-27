<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_ledgers', function (Blueprint $table): void {
            $table->uuid('application_operation_id')->nullable()->after('logical_request_id');
            $table->string('usage_unit', 40)->nullable()->after('output_tokens');
            $table->decimal('usage_quantity', 18, 6)->nullable()->after('usage_unit');
            $table->unsignedInteger('media_duration_ms')->nullable()->after('usage_quantity');
            $table->unique(
                ['application_operation_id', 'purpose', 'attempt'],
                'ai_usage_application_operation_unique',
            );
        });

        Schema::create('ai_common_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_common_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('operation_id');
            $table->string('event', 80);
            $table->string('subject_type', 40);
            $table->string('subject_public_id', 64)->nullable();
            $table->string('result', 24);
            $table->string('safe_error_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->char('event_fingerprint', 64);
            $table->timestamp('occurred_at_utc');
            $table->timestamps();

            $table->unique(['organization_id', 'event', 'operation_id'], 'ai_common_audit_operation_unique');
            $table->index(['organization_id', 'actor_user_id', 'occurred_at_utc'], 'ai_common_audit_actor_index');
            $table->index(['subject_type', 'subject_public_id'], 'ai_common_audit_subject_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_common_audit_events')
            && DB::table('ai_common_audit_events')->exists()) {
            throw new RuntimeException('Refusing to remove immutable Scope 11 audit evidence.');
        }
        if (Schema::hasColumn('ai_usage_ledgers', 'application_operation_id')
            && DB::table('ai_usage_ledgers')->whereNotNull('application_operation_id')->exists()) {
            throw new RuntimeException('Refusing to remove Scope 11 operation usage evidence.');
        }

        Schema::dropIfExists('ai_common_audit_events');
        Schema::table('ai_usage_ledgers', function (Blueprint $table): void {
            $table->dropUnique('ai_usage_application_operation_unique');
            $table->dropColumn(['application_operation_id', 'usage_unit', 'usage_quantity', 'media_duration_ms']);
        });
    }
};
