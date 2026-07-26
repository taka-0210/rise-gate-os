<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_plan_versions', 'version_type')) {
            Schema::table('project_plan_versions', function (Blueprint $table): void {
                $table->string('version_type', 24)->default('proposal')->after('source_proposal_id');
            });
        }

        if (! Schema::hasColumn('project_plan_versions', 'title')) {
            Schema::table('project_plan_versions', function (Blueprint $table): void {
                $table->string('title')->nullable()->after('version_type');
            });
        }

        if (! Schema::hasColumn('project_plan_versions', 'note')) {
            Schema::table('project_plan_versions', function (Blueprint $table): void {
                $table->text('note')->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['version_type', 'title', 'note'],
            fn (string $column): bool => Schema::hasColumn('project_plan_versions', $column),
        ));

        if ($columns !== []) {
            Schema::table('project_plan_versions', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
