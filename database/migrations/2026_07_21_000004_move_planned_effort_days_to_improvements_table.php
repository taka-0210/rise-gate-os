<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvements', function (Blueprint $table): void {
            $table->decimal('planned_effort_days', 7, 2)->nullable()->after('next_action');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('planned_effort_days');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->decimal('planned_effort_days', 7, 2)->nullable()->after('priority');
        });

        Schema::table('improvements', function (Blueprint $table): void {
            $table->dropColumn('planned_effort_days');
        });
    }
};
