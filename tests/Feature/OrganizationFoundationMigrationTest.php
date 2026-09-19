<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_three_schema_is_additive_and_migration_can_resume(): void
    {
        $migration = require database_path('migrations/2026_09_20_000002_add_scope_three_organization_foundation.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumns('organization_users', [
            'organization_role',
            'position',
            'membership_status',
        ]));
        $this->assertTrue(Schema::hasTable('organization_groups'));
        $this->assertTrue(Schema::hasTable('organization_group_memberships'));
        $this->assertTrue(Schema::hasTable('organization_audit_events'));
        $this->assertTrue(Schema::hasColumns('organization_users', [
            'role',
            'company_role',
            'permissions',
            'joined_at',
        ]));
    }

    public function test_backfill_maps_known_legacy_roles_without_changing_legacy_access_fields(): void
    {
        $legacyRoles = ['owner', 'admin', 'member', 'viewer', 'custom-role'];
        $membershipIds = [];

        foreach ($legacyRoles as $index => $legacyRole) {
            $user = User::factory()->create();
            $organization = Organization::create([
                'name' => 'Organization '.$index,
                'slug' => 'organization-'.$index,
            ]);
            $membershipIds[$legacyRole] = DB::table('organization_users')->insertGetId([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $legacyRole,
                'organization_role' => null,
                'position' => null,
                'membership_status' => OrganizationUser::STATUS_ACTIVE,
                'company_role' => OrganizationUser::COMPANY_ROLE_ACCOUNTING,
                'permissions' => json_encode([OrganizationUser::PERMISSION_FINANCE_VIEW_PL]),
                'joined_at' => '2026-09-20 12:34:56',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $before = DB::table('organization_users')
            ->whereIn('id', array_values($membershipIds))
            ->get()
            ->keyBy('id');

        (require database_path('migrations/2026_09_20_000002_add_scope_three_organization_foundation.php'))->up();

        $expected = [
            'owner' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'admin' => OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            'member' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'viewer' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'custom-role' => null,
        ];

        foreach ($membershipIds as $legacyRole => $id) {
            $after = DB::table('organization_users')->find($id);
            $this->assertSame($expected[$legacyRole], $after->organization_role);
            $this->assertSame($before[$id]->role, $after->role);
            $this->assertSame($before[$id]->company_role, $after->company_role);
            $this->assertSame($before[$id]->permissions, $after->permissions);
            $this->assertSame($before[$id]->joined_at, $after->joined_at);
            $this->assertSame(OrganizationUser::STATUS_ACTIVE, $after->membership_status);
        }
    }
}
