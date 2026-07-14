<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('improvement_id')->constrained()->cascadeOnDelete();
            $table->string('output_type');
            $table->unsignedBigInteger('output_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['improvement_id', 'output_type']);
            $table->index(['output_type', 'output_id']);
            $table->unique(['improvement_id', 'output_type', 'output_id'], 'improvement_outputs_unique_output');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_outputs');
    }
};