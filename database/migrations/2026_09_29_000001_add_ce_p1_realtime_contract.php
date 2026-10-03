<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_common_shared_relay_leases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ai_common_conversation_id');
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('ai_common_shared_capture_stream_id');
            $table->unsignedBigInteger('purpose_revision_id');
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('lease_version')->default(1);
            $table->string('state', 24);
            $table->char('audience_fingerprint', 64);
            $table->char('consent_fingerprint', 64);
            $table->char('membership_fingerprint', 64);
            $table->char('credential_fingerprint', 64);
            $table->timestamp('issued_at_utc');
            $table->timestamp('expires_at_utc');
            $table->timestamp('refreshed_at_utc')->nullable();
            $table->timestamp('revoked_at_utc')->nullable();
            $table->timestamp('closed_at_utc')->nullable();
            $table->string('safe_reason_code', 80)->nullable();
            $table->timestamps();
            $table->foreign('organization_id', 'acrlease_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('ai_common_conversation_id', 'acrlease_conversation_fk')->references('id')->on('ai_common_conversations')->restrictOnDelete();
            $table->foreign('ai_common_shared_session_id', 'acrlease_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('ai_common_shared_capture_stream_id', 'acrlease_stream_fk')->references('id')->on('ai_common_shared_capture_streams')->restrictOnDelete();
            $table->foreign('purpose_revision_id', 'acrlease_purpose_fk')->references('id')->on('ai_common_shared_purpose_revisions')->restrictOnDelete();
            $table->unique(['ai_common_shared_capture_stream_id', 'generation'], 'acrlease_stream_generation_uq');
            $table->index(['ai_common_shared_session_id', 'state'], 'acrlease_session_state_idx');
            $table->index(['expires_at_utc', 'state'], 'acrlease_expiry_state_idx');
        });

        Schema::create('ai_common_shared_relay_control_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->unsignedBigInteger('relay_lease_id');
            $table->string('event_type', 40);
            $table->unsignedBigInteger('target_generation');
            $table->unsignedBigInteger('cutoff_sample')->nullable();
            $table->string('safe_reason_code', 80);
            $table->timestamp('occurred_at_utc');
            $table->timestamp('delivered_at_utc')->nullable();
            $table->timestamp('acknowledged_at_utc')->nullable();
            $table->string('acknowledgement', 40)->nullable();
            $table->timestamps();
            $table->foreign('relay_lease_id', 'acrcontrol_lease_fk')->references('id')->on('ai_common_shared_relay_leases')->restrictOnDelete();
            $table->index(['relay_lease_id', 'occurred_at_utc'], 'acrcontrol_lease_time_idx');
            $table->index('delivered_at_utc', 'acrcontrol_delivery_idx');
        });

        Schema::create('ai_common_shared_source_ranges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('ai_common_shared_session_id');
            $table->unsignedBigInteger('ai_common_shared_capture_stream_id');
            $table->unsignedBigInteger('relay_lease_id');
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('frame_sequence');
            $table->uuid('client_event_id');
            $table->unsignedBigInteger('start_sample');
            $table->unsignedBigInteger('end_sample');
            $table->unsignedInteger('sample_rate');
            $table->unsignedSmallInteger('bit_depth');
            $table->unsignedSmallInteger('channels');
            $table->string('format', 24);
            $table->char('content_sha256', 64);
            $table->char('authorization_fingerprint', 64);
            $table->char('consent_fingerprint', 64);
            $table->string('state', 24);
            $table->string('safe_reason_code', 80)->nullable();
            $table->timestamp('received_at_utc');
            $table->timestamp('accepted_at_utc')->nullable();
            $table->timestamps();
            $table->foreign('ai_common_shared_session_id', 'acrrange_session_fk')->references('id')->on('ai_common_shared_sessions')->restrictOnDelete();
            $table->foreign('ai_common_shared_capture_stream_id', 'acrrange_stream_fk')->references('id')->on('ai_common_shared_capture_streams')->restrictOnDelete();
            $table->foreign('relay_lease_id', 'acrrange_lease_fk')->references('id')->on('ai_common_shared_relay_leases')->restrictOnDelete();
            $table->unique(['ai_common_shared_capture_stream_id', 'generation', 'frame_sequence'], 'acrrange_stream_generation_sequence_uq');
            $table->unique(['ai_common_shared_capture_stream_id', 'generation', 'client_event_id'], 'acrrange_stream_generation_event_uq');
            $table->index(['ai_common_shared_capture_stream_id', 'start_sample', 'end_sample'], 'acrrange_stream_range_idx');
            $table->index(['state', 'received_at_utc'], 'acrrange_state_retention_idx');
        });

        Schema::create('ai_common_shared_provider_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('relay_lease_id');
            $table->unsignedBigInteger('ai_common_shared_capture_stream_id');
            $table->unsignedBigInteger('generation');
            $table->string('adapter', 40);
            $table->string('adapter_version', 80);
            $table->string('capability_profile_version', 80);
            $table->char('provider_session_reference_hash', 64)->nullable();
            $table->string('state', 24);
            $table->unsignedBigInteger('receive_order')->default(0);
            $table->timestamp('opened_at_utc')->nullable();
            $table->timestamp('closing_at_utc')->nullable();
            $table->timestamp('closed_at_utc')->nullable();
            $table->string('safe_reason_code', 80)->nullable();
            $table->timestamps();
            $table->foreign('relay_lease_id', 'acrprovider_lease_fk')->references('id')->on('ai_common_shared_relay_leases')->restrictOnDelete();
            $table->foreign('ai_common_shared_capture_stream_id', 'acrprovider_stream_fk')->references('id')->on('ai_common_shared_capture_streams')->restrictOnDelete();
            $table->unique(['ai_common_shared_capture_stream_id', 'generation'], 'acrprovider_stream_generation_uq');
            $table->index(['relay_lease_id', 'state'], 'acrprovider_lease_state_idx');
        });

        Schema::create('ai_common_shared_provider_send_ranges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('provider_session_id');
            $table->unsignedBigInteger('source_range_id');
            $table->unsignedBigInteger('send_ordinal');
            $table->unsignedBigInteger('provider_offset_start_sample');
            $table->unsignedBigInteger('provider_offset_end_sample');
            $table->string('state', 24);
            $table->timestamp('sent_at_utc');
            $table->timestamp('acknowledged_at_utc')->nullable();
            $table->timestamps();
            $table->foreign('provider_session_id', 'acrsend_provider_fk')->references('id')->on('ai_common_shared_provider_sessions')->restrictOnDelete();
            $table->foreign('source_range_id', 'acrsend_range_fk')->references('id')->on('ai_common_shared_source_ranges')->restrictOnDelete();
            $table->unique(['provider_session_id', 'send_ordinal'], 'acrsend_provider_ordinal_uq');
            $table->unique(['provider_session_id', 'source_range_id'], 'acrsend_provider_range_uq');
        });

        Schema::create('ai_common_shared_provider_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('provider_session_id');
            $table->char('provider_event_identity_hash', 64)->nullable();
            $table->unsignedBigInteger('provider_sequence')->nullable();
            $table->unsignedBigInteger('receive_order');
            $table->string('normalized_event_type', 24);
            $table->unsignedBigInteger('duplicate_of_id')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->unsignedBigInteger('provider_start_sample')->nullable();
            $table->unsignedBigInteger('provider_duration_samples')->nullable();
            $table->unsignedBigInteger('verified_source_start_sample')->nullable();
            $table->unsignedBigInteger('verified_source_end_sample')->nullable();
            $table->string('range_verification_state', 24);
            $table->char('final_content_sha256', 64)->nullable();
            $table->text('normalized_final_metadata')->nullable();
            $table->string('status', 24);
            $table->string('safe_reason_code', 80)->nullable();
            $table->timestamp('received_at_utc');
            $table->timestamp('finalized_at_utc')->nullable();
            $table->timestamp('rejected_at_utc')->nullable();
            $table->timestamp('superseded_at_utc')->nullable();
            $table->decimal('usage_quantity', 20, 6)->nullable();
            $table->string('usage_unit', 32)->nullable();
            $table->string('price_version', 64)->nullable();
            $table->unsignedBigInteger('estimated_cost_microunits')->nullable();
            $table->timestamps();
            $table->foreign('provider_session_id', 'acrreceipt_provider_fk')->references('id')->on('ai_common_shared_provider_sessions')->restrictOnDelete();
            $table->foreign('duplicate_of_id', 'acrreceipt_duplicate_fk')->references('id')->on('ai_common_shared_provider_event_receipts')->restrictOnDelete();
            $table->foreign('supersedes_id', 'acrreceipt_supersedes_fk')->references('id')->on('ai_common_shared_provider_event_receipts')->restrictOnDelete();
            $table->unique(['provider_session_id', 'receive_order'], 'acrreceipt_provider_order_uq');
            $table->unique(['provider_session_id', 'provider_event_identity_hash'], 'acrreceipt_provider_identity_uq');
            $table->index(['provider_session_id', 'status'], 'acrreceipt_provider_status_idx');
        });

        Schema::create('ai_common_shared_durable_final_commits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('provider_event_receipt_id')->unique('acrcommit_receipt_unique');
            $table->uuid('writer_operation_id')->unique('acrcommit_writer_operation_unique');
            $table->unsignedBigInteger('validated_source_start_sample');
            $table->unsignedBigInteger('validated_source_end_sample');
            $table->string('state', 24);
            $table->string('safe_error_code', 80)->nullable();
            $table->timestamp('committed_at_utc')->nullable();
            $table->timestamps();
            $table->foreign('provider_event_receipt_id', 'acrcommit_receipt_fk')->references('id')->on('ai_common_shared_provider_event_receipts')->restrictOnDelete();
        });

        Schema::create('ai_common_shared_durable_final_commit_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('durable_final_commit_id');
            $table->unsignedBigInteger('transcript_segment_id');
            $table->unsignedBigInteger('transcript_revision_id');
            $table->unsignedInteger('ordinal');
            $table->timestamps();
            $table->foreign('durable_final_commit_id', 'acrcommititem_commit_fk')->references('id')->on('ai_common_shared_durable_final_commits')->restrictOnDelete();
            $table->foreign('transcript_segment_id', 'acrcommititem_segment_fk')->references('id')->on('ai_common_shared_transcript_segments')->restrictOnDelete();
            $table->foreign('transcript_revision_id', 'acrcommititem_revision_fk')->references('id')->on('ai_common_shared_transcript_revisions')->restrictOnDelete();
            $table->unique(['durable_final_commit_id', 'ordinal'], 'acrcommititem_commit_ordinal_uq');
            $table->unique(['durable_final_commit_id', 'transcript_revision_id'], 'acrcommititem_commit_revision_uq');
        });
    }

    public function down(): void
    {
        $tables = [
            'ai_common_shared_durable_final_commit_items', 'ai_common_shared_durable_final_commits',
            'ai_common_shared_provider_event_receipts', 'ai_common_shared_provider_send_ranges',
            'ai_common_shared_provider_sessions', 'ai_common_shared_source_ranges',
            'ai_common_shared_relay_control_events', 'ai_common_shared_relay_leases',
        ];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Realtime Evidence exists; preserve history instead of destructive rollback.');
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
