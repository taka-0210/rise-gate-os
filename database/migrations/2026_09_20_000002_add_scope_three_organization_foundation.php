<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MEMBERSHIP_INDEX = 'organization_users_org_role_status_idx';

    public function up(): void
    {
        if (! Schema::hasColumn('organization_users', 'organization_role')) {
            Schema::table('organization_users', function (Blueprint $table): void {
                $table->string('organization_role', 20)->nullable()->after('role');
            });
        }

        if (! Schema::hasColumn('organization_users', 'position')) {
            Schema::table('organization_users', function (Blueprint $table): void {
                $table->string('position', 100)->nullable()->after('organization_role');
            });
        }

        if (! Schema::hasColumn('organization_users', 'membership_status')) {
            Schema::table('organization_users', function (Blueprint $table): void {
                $table->string('membership_status', 20)->default('active')->after('position');
            });
        }

        DB::table('organization_users')
            ->whereNull('organization_role')
            ->where('role', 'owner')
            ->update(['organization_role' => 'owner']);
        DB::table('organization_users')
            ->whereNull('organization_role')
            ->where('role', 'admin')
            ->update(['organization_role' => 'admin']);
        DB::table('organization_users')
            ->whereNull('organization_role')
            ->whereIn('role', ['member', 'viewer'])
            ->update(['organization_role' => 'member']);
        DB::table('organization_users')
            ->whereNull('membership_status')
            ->update(['membership_status' => 'active']);

        if (! $this->hasIndex('organization_users', self::MEMBERSHIP_INDEX)) {
            Schema::table('organization_users', function (Blueprint $table): void {
                $table->index(
                    ['organization_id', 'organization_role', 'membership_status'],
                    self::MEMBERSHIP_INDEX,
                );
            });
        }

        if (! Schema::hasTable('organization_groups')) {
            Schema::create('organization_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->timestamp('archived_at')->nullable();
                $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['organization_id', 'name']);
                $table->index(['organization_id', 'archived_at']);
            });
        }

        if (! Schema::hasTable('organization_group_memberships')) {
            Schema::create('organization_group_memberships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_group_id')->constrained('organization_groups')->cascadeOnDelete();
                $table->foreignId('organization_user_id')->constrained('organization_users')->cascadeOnDelete();
                $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['organization_group_id', 'organization_user_id'],
                    'organization_group_memberships_unique',
                );
                $table->index('organization_user_id');
            });
        }

        if (! Schema::hasTable('organization_audit_events')) {
            Schema::create('organization_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('organization_group_id')->nullable()->constrained('organization_groups')->nullOnDelete();
                $table->string('event', 80);
                $table->string('outcome', 20);
                $table->json('before_data')->nullable();
                $table->json('after_data')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['organization_id', 'occurred_at']);
                $table->index(['actor_user_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_audit_events');
        Schema::dropIfExists('organization_group_memberships');
        Schema::dropIfExists('organization_groups');

        if (Schema::hasTable('organization_users')) {
            Schema::table('organization_users', function (Blueprint $table): void {
                if ($this->hasIndex('organization_users', self::MEMBERSHIP_INDEX)) {
                    $table->dropIndex(self::MEMBERSHIP_INDEX);
                }

                $columns = array_values(array_filter(
                    ['organization_role', 'position', 'membership_status'],
                    static fn (string $column): bool => Schema::hasColumn('organization_users', $column),
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['name'] ?? null) === $name,
        );
    }
};
