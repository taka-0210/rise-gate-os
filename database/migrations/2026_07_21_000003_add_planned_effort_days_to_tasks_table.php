<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->decimal('planned_effort_days', 7, 2)->nullable()->after('priority');
        });

        DB::table('tasks')->orderBy('id')->chunkById(200, function ($tasks): void {
            foreach ($tasks as $task) {
                $effort = 1;
                if ($task->planned_start_day !== null && $task->due_day !== null) {
                    $effort = max(0.25, $task->due_day - $task->planned_start_day + 1);
                } elseif ($task->planned_start_date !== null && $task->due_date !== null) {
                    $effort = max(0.25, Carbon::parse($task->planned_start_date)->diffInDays(Carbon::parse($task->due_date)) + 1);
                }
                DB::table('tasks')->where('id', $task->id)->update(['planned_effort_days' => $effort]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('planned_effort_days');
        });
    }
};
