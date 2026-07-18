<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table): void {
            $table->date('planned_start_date')->nullable()->after('purpose');
            $table->date('target_date')->nullable()->after('planned_start_date');
            $table->date('reached_at')->nullable()->after('target_date');
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table): void {
            $table->dropColumn(['planned_start_date', 'target_date', 'reached_at']);
        });
    }
};
