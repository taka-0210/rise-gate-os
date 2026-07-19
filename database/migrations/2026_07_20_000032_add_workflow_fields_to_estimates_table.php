<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table): void {
            $table->ulid('revision_group')->nullable()->after('estimate_number');
            $table->unsignedInteger('revision_no')->default(1)->after('revision_group');
            $table->boolean('is_current')->default(true)->after('revision_no');
            $table->foreignId('previous_estimate_id')->nullable()->after('is_current')->constrained('estimates')->nullOnDelete();
            $table->string('client_access_token', 64)->nullable()->unique()->after('status');
            $table->timestamp('submitted_at')->nullable()->after('client_access_token');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('client_viewed_at')->nullable()->after('submitted_by');
            $table->timestamp('responded_at')->nullable()->after('client_viewed_at');
            $table->text('response_note')->nullable()->after('responded_at');
            $table->date('ordered_on')->nullable()->after('response_note');
            $table->text('lost_reason')->nullable()->after('ordered_on');
            $table->timestamp('voided_at')->nullable()->after('lost_reason');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->index(['workspace_id', 'is_current', 'status']);
            $table->index(['revision_group', 'revision_no']);
        });
        DB::table('estimates')->whereNull('revision_group')->update(['revision_group' => DB::raw('public_id')]);
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table): void {
            $table->dropForeign(['previous_estimate_id']);
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['voided_by']);
            $table->dropIndex(['workspace_id', 'is_current', 'status']);
            $table->dropIndex(['revision_group', 'revision_no']);
            $table->dropColumn(['revision_group','revision_no','is_current','previous_estimate_id','client_access_token','submitted_at','submitted_by','client_viewed_at','responded_at','response_note','ordered_on','lost_reason','voided_at','voided_by']);
        });
    }
};
