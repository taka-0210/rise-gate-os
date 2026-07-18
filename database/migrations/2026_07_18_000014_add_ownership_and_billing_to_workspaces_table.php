<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
            $table->string('billing_type')->default('included')->after('slug');
            $table->string('status')->default('active')->after('billing_type');
            $table->string('purpose')->nullable()->after('status');
        });

        $includedOwners = [];
        $workspaces = DB::table('workspaces')->orderBy('id')->get(['id']);

        foreach ($workspaces as $workspace) {
            $ownerId = DB::table('workspace_members')
                ->where('workspace_id', $workspace->id)
                ->where('role', 'owner')
                ->orderBy('id')
                ->value('user_id');

            if (! $ownerId) {
                continue;
            }

            $billingType = isset($includedOwners[$ownerId]) ? 'additional' : 'included';
            $includedOwners[$ownerId] = true;

            DB::table('workspaces')->where('id', $workspace->id)->update([
                'owner_user_id' => $ownerId,
                'billing_type' => $billingType,
                'status' => 'active',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn(['owner_user_id', 'billing_type', 'status', 'purpose']);
        });
    }
};
