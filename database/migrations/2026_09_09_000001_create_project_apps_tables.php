<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_apps', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name', 120);
            $table->longText('html');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('project_app_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_app_id')->constrained()->cascadeOnDelete();
            $table->string('login', 80);
            $table->string('name', 120);
            $table->string('password');
            $table->string('role', 10)->default('staff');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['project_app_id', 'login']);
        });
        Schema::create('project_app_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_app_account_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
        });
        Schema::create('project_app_data', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_app_account_id')->constrained()->cascadeOnDelete();
            $table->longText('data');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['project_app_id', 'project_app_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_app_data');
        Schema::dropIfExists('project_app_sessions');
        Schema::dropIfExists('project_app_accounts');
        Schema::dropIfExists('project_apps');
    }
};
