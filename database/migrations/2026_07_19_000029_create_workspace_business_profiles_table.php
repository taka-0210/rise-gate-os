<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_business_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('representative_title')->nullable();
            $table->string('representative_name')->nullable();
            $table->string('invoice_registration_number', 20)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_original_name')->nullable();
            $table->string('seal_path')->nullable();
            $table->string('seal_original_name')->nullable();
            $table->text('document_note')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('branch_name')->nullable();
            $table->string('account_type', 20)->default('ordinary');
            $table->string('account_number', 30);
            $table->string('account_holder');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['workspace_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_bank_accounts');
        Schema::dropIfExists('workspace_business_profiles');
    }
};
