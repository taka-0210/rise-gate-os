<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('improvement_id');
            $table->index(['improvement_id', 'sort_order']);
        });

        DB::table('tasks')->orderBy('improvement_id')->orderBy('id')->get()->groupBy('improvement_id')->each(function ($tasks): void {
            foreach ($tasks->values() as $index => $task) {
                DB::table('tasks')->where('id', $task->id)->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['improvement_id', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
