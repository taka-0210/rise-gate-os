<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_observations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->date('occurred_on')->nullable();
            $table->timestamp('observed_at');
            $table->foreignId('observer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type');
            $table->string('source_name')->nullable();
            $table->text('source_note')->nullable();
            $table->string('status')->default('recorded');
            $table->string('importance')->default('unreviewed');
            $table->text('next_observation')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'observed_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('company_senses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->text('interpretation');
            $table->text('hypothesis')->nullable();
            $table->string('status')->default('proposed');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('company_observation_sense', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_observation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_sense_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_type')->default('interprets');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_observation_id', 'company_sense_id'], 'company_observation_sense_unique');
        });

        Schema::create('company_improvements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('background')->nullable();
            $table->text('current_state')->nullable();
            $table->text('desired_state');
            $table->text('reason')->nullable();
            $table->text('hypothesis')->nullable();
            $table->text('expected_effect')->nullable();
            $table->string('priority')->default('normal');
            $table->string('status')->default('discovered');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'priority']);
        });

        Schema::create('company_improvement_observation', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_improvement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_observation_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_type')->default('discovered_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_improvement_id', 'company_observation_id'], 'company_improvement_observation_unique');
        });

        Schema::create('company_improvement_sense', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_improvement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_sense_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_type')->default('suggests');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_improvement_id', 'company_sense_id'], 'company_improvement_sense_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_improvement_sense');
        Schema::dropIfExists('company_improvement_observation');
        Schema::dropIfExists('company_improvements');
        Schema::dropIfExists('company_observation_sense');
        Schema::dropIfExists('company_senses');
        Schema::dropIfExists('company_observations');
    }
};
