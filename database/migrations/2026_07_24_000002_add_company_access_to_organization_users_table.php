<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_users', function (Blueprint $table): void {
            $table->string('company_role', 30)->nullable()->after('role');
            $table->json('permissions')->nullable()->after('company_role');
        });
    }

    public function down(): void
    {
        Schema::table('organization_users', function (Blueprint $table): void {
            $table->dropColumn(['company_role', 'permissions']);
        });
    }
};
