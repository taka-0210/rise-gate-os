<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'standard_workspace_id')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->foreignId('standard_workspace_id')
                    ->nullable()
                    ->after('fiscal_year_end_month')
                    ->constrained('workspaces')
                    ->nullOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('credential_generation');
            }
            if (! Schema::hasColumn('users', 'avatar_mime')) {
                $table->string('avatar_mime', 30)->nullable()->after('avatar_path');
            }
            if (! Schema::hasColumn('users', 'avatar_width')) {
                $table->unsignedSmallInteger('avatar_width')->nullable()->after('avatar_mime');
            }
            if (! Schema::hasColumn('users', 'avatar_height')) {
                $table->unsignedSmallInteger('avatar_height')->nullable()->after('avatar_width');
            }
            if (! Schema::hasColumn('users', 'avatar_updated_at')) {
                $table->timestamp('avatar_updated_at')->nullable()->after('avatar_height');
            }
        });

        if (! Schema::hasTable('organization_invitations')) {
            Schema::create('organization_invitations', function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('sponsor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('normalized_email');
                $table->string('intended_organization_role', 20);
                $table->string('status', 20)->default('pending');
                $table->string('pending_email_key')->nullable();
                $table->char('token_hash', 64);
                $table->unsignedBigInteger('token_generation')->default(1);
                $table->timestamp('expires_at');
                $table->foreignId('organization_user_id')->nullable()->constrained('organization_users')->nullOnDelete();
                $table->foreignId('claimed_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('accepted_at')->nullable();
                $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->string('delivery_status', 20)->default('pending');
                $table->timestamp('delivery_requested_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('delivery_failed_at')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'pending_email_key'], 'organization_invitations_pending_email_unique');
                $table->index(['organization_id', 'status', 'expires_at'], 'organization_invitations_status_idx');
                $table->index(['claimed_user_id', 'status']);
            });
        }

        if (! Schema::hasTable('organization_invitation_groups')) {
            Schema::create('organization_invitation_groups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_invitation_id')->constrained('organization_invitations')->cascadeOnDelete();
                $table->foreignId('organization_group_id')->constrained('organization_groups')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['organization_invitation_id', 'organization_group_id'],
                    'organization_invitation_groups_unique',
                );
                $table->index('organization_group_id');
            });
        }

        if (! Schema::hasTable('organization_invitation_operations')) {
            Schema::create('organization_invitation_operations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('organization_invitation_id')->nullable()->constrained('organization_invitations')->nullOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('operation', 20);
                $table->string('request_id', 64);
                $table->char('payload_hash', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'operation', 'request_id'],
                    'organization_invitation_operations_request_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitation_operations');
        Schema::dropIfExists('organization_invitation_groups');
        Schema::dropIfExists('organization_invitations');

        Schema::table('users', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['avatar_path', 'avatar_mime', 'avatar_width', 'avatar_height', 'avatar_updated_at'],
                static fn (string $column): bool => Schema::hasColumn('users', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        if (Schema::hasColumn('organizations', 'standard_workspace_id')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('standard_workspace_id');
            });
        }
    }
};
