<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('estimate_number');
            $table->string('title');
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->string('status', 30)->default('draft');
            $table->json('issuer_snapshot');
            $table->json('client_snapshot');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'estimate_number']);
            $table->index(['workspace_id', 'status', 'issued_on']);
        });

        Schema::create('estimate_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_public_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 30)->default('式');
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(10);
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
    }
};
