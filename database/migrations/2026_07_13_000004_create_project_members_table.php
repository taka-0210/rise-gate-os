<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('project_role')->default('viewer');
            $table->string('permission_level')->default('view');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['workspace_id', 'user_id']);
            $table->index(['project_id', 'permission_level']);
        });

        Project::query()->whereNotNull('owner_user_id')->each(function (Project $project): void {
            DB::table('project_members')->updateOrInsert(
                [
                    'project_id' => $project->id,
                    'user_id' => $project->owner_user_id,
                ],
                [
                    'workspace_id' => $project->owning_workspace_id,
                    'project_role' => 'owner',
                    'permission_level' => 'admin',
                    'invited_by' => $project->owner_user_id,
                    'invited_at' => now(),
                    'accepted_at' => now(),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
