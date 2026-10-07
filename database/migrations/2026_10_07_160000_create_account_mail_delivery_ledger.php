<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('dedupe_key', 64)->unique();
            $table->char('payload_hash', 64);
            $table->char('recipient_hash', 64);
            $table->string('provider', 40);
            $table->string('provider_message_id', 190)->nullable()->unique();
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('event_at')->nullable();
            $table->timestamps();
        });
        Schema::create('account_mail_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('account_mail_deliveries');
            $table->char('event_key', 64)->unique();
            $table->string('type', 32);
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mail_provider_events');
        Schema::dropIfExists('account_mail_deliveries');
    }
};
