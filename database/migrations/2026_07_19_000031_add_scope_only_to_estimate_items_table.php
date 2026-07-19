<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table): void {
            $table->boolean('is_scope_only')->default(false)->after('source_public_id');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table): void {
            $table->dropColumn('is_scope_only');
        });
    }
};
