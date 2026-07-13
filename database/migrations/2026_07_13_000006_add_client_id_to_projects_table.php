<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('billing_workspace_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['client_id', 'owning_workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id', 'owning_workspace_id']);
            $table->dropColumn('client_id');
        });
    }
};
