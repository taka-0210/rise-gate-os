<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvements', function (Blueprint $table): void {
            $table->foreignId('roadmap_id')->nullable()->after('project_id')->constrained('roadmaps')->nullOnDelete();
            $table->unsignedInteger('roadmap_sort_order')->nullable()->after('roadmap_id');
            $table->index(['project_id', 'roadmap_id']);
        });
    }

    public function down(): void
    {
        Schema::table('improvements', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'roadmap_id']);
            $table->dropConstrainedForeignId('roadmap_id');
            $table->dropColumn('roadmap_sort_order');
        });
    }
};
