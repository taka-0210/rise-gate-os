<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_domains', function (Blueprint $table): void {
            $table->string('direction', 32)->nullable()->after('self_recognized_strengths');
            $table->longText('direction_memo')->nullable()->after('direction');
            $table->unsignedInteger('display_order')->default(0)->after('status');

            $table->index(
                ['organization_id', 'status', 'display_order'],
                'business_domains_display_order_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('business_domains', function (Blueprint $table): void {
            $table->dropIndex('business_domains_display_order_idx');
            $table->dropColumn(['direction', 'direction_memo', 'display_order']);
        });
    }
};
