<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('management_design_items', function (Blueprint $table): void {
            $table->longText('statement_explanation')->nullable();
        });

        Schema::table('management_design_sections', function (Blueprint $table): void {
            $table->longText('explanation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('management_design_sections', function (Blueprint $table): void {
            $table->dropColumn('explanation');
        });

        Schema::table('management_design_items', function (Blueprint $table): void {
            $table->dropColumn('statement_explanation');
        });
    }
};
