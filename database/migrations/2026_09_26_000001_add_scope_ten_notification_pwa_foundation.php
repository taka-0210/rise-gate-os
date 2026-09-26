<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_notification_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_confirmed')->default(false);
            $table->json('weekday_windows')->nullable();
            $table->string('timezone', 64)->default('Asia/Tokyo');
            $table->time('quiet_starts_at')->nullable();
            $table->time('quiet_ends_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('organization_notification_calendar_dates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->date('calendar_date');
            $table->string('kind', 24); // holiday | working_exception
            $table->string('label', 120)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'calendar_date'], 'onc_org_date_uq');
        });

        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('push_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('email_fallback_enabled')->default(false);
            $table->time('quiet_starts_at')->nullable();
            $table->time('quiet_ends_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id'], 'unp_org_user_uq');
        });

        Schema::create('company_notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 48);
            $table->string('source_type', 48);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 64);
            $table->string('dedupe_key', 190)->unique();
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->string('deep_link_path', 500);
            $table->string('timing', 24)->default('now');
            $table->dateTime('content_visible_at_utc');
            $table->dateTime('eligible_at_utc');
            $table->unsignedBigInteger('membership_access_epoch');
            $table->unsignedBigInteger('credential_generation');
            $table->dateTime('read_at_utc')->nullable();
            $table->dateTime('source_seen_at_utc')->nullable();
            $table->dateTime('action_done_at_utc')->nullable();
            $table->dateTime('cancelled_at_utc')->nullable();
            $table->string('cancellation_reason', 120)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'recipient_user_id', 'content_visible_at_utc'], 'cn_center_idx');
            $table->index(['recipient_user_id', 'read_at_utc'], 'cn_unread_idx');
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_notification_id')->constrained()->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('status', 24)->default('pending');
            $table->dateTime('available_at_utc');
            $table->dateTime('leased_until_utc')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('delivered_at_utc')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['company_notification_id', 'channel'], 'nd_notification_channel_uq');
            $table->index(['status', 'available_at_utc'], 'nd_due_idx');
        });

        Schema::create('notification_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_delivery_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->string('outcome', 24);
            $table->string('reason_code', 80)->nullable();
            $table->string('provider_receipt_hash', 64)->nullable();
            $table->dateTime('attempted_at_utc');
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint_encrypted');
            $table->text('p256dh_encrypted');
            $table->text('auth_encrypted');
            $table->unsignedInteger('generation')->default(1);
            $table->dateTime('revoked_at_utc')->nullable();
            $table->dateTime('last_used_at_utc')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'revoked_at_utc'], 'ps_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('company_notifications');
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('organization_notification_calendar_dates');
        Schema::dropIfExists('organization_notification_policies');
    }
};
