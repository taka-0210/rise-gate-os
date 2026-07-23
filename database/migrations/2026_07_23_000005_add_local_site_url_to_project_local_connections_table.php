<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_local_connections', function (Blueprint $table) {
            $table->string('local_site_url')->nullable()->after('local_path');
        });
    }

    public function down(): void
    {
        Schema::table('project_local_connections', function (Blueprint $table) {
            $table->dropColumn('local_site_url');
        });
    }
};
