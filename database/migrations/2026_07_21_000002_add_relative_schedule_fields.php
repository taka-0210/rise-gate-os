<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedInteger('duration_days')->nullable()->after('due_date');
        });

        Schema::table('roadmaps', function (Blueprint $table): void {
            $table->unsignedInteger('planned_start_day')->nullable()->after('target_date');
            $table->unsignedInteger('target_day')->nullable()->after('planned_start_day');
        });

        Schema::table('improvements', function (Blueprint $table): void {
            $table->unsignedInteger('planned_start_day')->nullable()->after('target_date');
            $table->unsignedInteger('target_day')->nullable()->after('planned_start_day');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedInteger('planned_start_day')->nullable()->after('due_date');
            $table->unsignedInteger('due_day')->nullable()->after('planned_start_day');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['planned_start_day', 'due_day']));
        Schema::table('improvements', fn (Blueprint $table) => $table->dropColumn(['planned_start_day', 'target_day']));
        Schema::table('roadmaps', fn (Blueprint $table) => $table->dropColumn(['planned_start_day', 'target_day']));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('duration_days'));
    }
};
