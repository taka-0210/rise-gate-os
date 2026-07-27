<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->string('mode', 32)->default('differential')->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
    }
};
