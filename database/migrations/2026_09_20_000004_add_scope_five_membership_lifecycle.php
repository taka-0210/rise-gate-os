<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('organization_users', 'access_epoch')) {
                $table->unsignedBigInteger('access_epoch')->default(1)->after('membership_status');
            }
            if (! Schema::hasColumn('organization_users', 'lifecycle_version')) {
                $table->unsignedBigInteger('lifecycle_version')->default(1)->after('access_epoch');
            }
            if (! Schema::hasColumn('organization_users', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable()->after('lifecycle_version');
            }
            if (! Schema::hasColumn('organization_users', 'status_changed_by_user_id')) {
                $table->foreignId('status_changed_by_user_id')
                    ->nullable()
                    ->after('status_changed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('organization_users', 'status_change_reason')) {
                $table->string('status_change_reason', 500)->nullable()->after('status_changed_by_user_id');
            }
        });

        if (! Schema::hasTable('organization_membership_lifecycle_operations')) {
            Schema::create('organization_membership_lifecycle_operations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->foreignId('organization_user_id');
                $table->foreignId('actor_user_id')->nullable();
                $table->string('command', 20);
                $table->string('request_id', 64);
                $table->char('payload_hash', 64);
                $table->unsignedBigInteger('expected_version');
                $table->string('result_status', 20);
                $table->unsignedBigInteger('result_version');
                $table->unsignedBigInteger('result_access_epoch');
                $table->unsignedInteger('revoked_ai_key_count')->default(0);
                $table->unsignedInteger('revoked_invitation_count')->default(0);
                $table->timestamps();
                $table->foreign('organization_id', 'membership_lifecycle_org_fk')
                    ->references('id')->on('organizations')->cascadeOnDelete();
                $table->foreign('organization_user_id', 'membership_lifecycle_membership_fk')
                    ->references('id')->on('organization_users')->cascadeOnDelete();
                $table->foreign('actor_user_id', 'membership_lifecycle_actor_fk')
                    ->references('id')->on('users')->nullOnDelete();

                $table->unique(
                    ['organization_id', 'request_id'],
                    'organization_membership_lifecycle_request_unique',
                );
                $table->index(
                    ['organization_user_id', 'created_at'],
                    'organization_membership_lifecycle_membership_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_membership_lifecycle_operations');

        Schema::table('organization_users', function (Blueprint $table): void {
            if (Schema::hasColumn('organization_users', 'status_changed_by_user_id')) {
                $table->dropConstrainedForeignId('status_changed_by_user_id');
            }

            $columns = array_values(array_filter(
                ['access_epoch', 'lifecycle_version', 'status_changed_at', 'status_change_reason'],
                static fn (string $column): bool => Schema::hasColumn('organization_users', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
