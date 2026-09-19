<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('credential_generation')->default(1)->after('remember_token');
        });

        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('credential_generation')->default(1)->after('token');
        });

        Schema::create('account_email_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('current_email');
            $table->string('pending_email')->nullable();
            $table->string('token_hash', 64);
            $table->unsignedBigInteger('credential_generation');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'status'], 'account_email_requests_user_purpose_status');
        });

        Schema::create('account_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 80);
            $table->string('outcome', 24);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_security_events');
        Schema::dropIfExists('account_email_requests');

        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropColumn('credential_generation');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('credential_generation');
        });
    }
};
