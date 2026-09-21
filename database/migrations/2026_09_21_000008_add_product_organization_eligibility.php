<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_account_eligibilities')) {
            Schema::create('product_account_eligibilities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
                $table->string('mode', 30);
                $table->foreignId('product_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
                $table->string('classification_version', 50);
                $table->timestamp('classified_at');
                $table->string('evidence_ref', 255);
                $table->timestamps();

                $table->index(['mode', 'product_organization_id'], 'product_account_eligibility_mode_org_idx');
            });

            $validModeAndOrganization = <<<'SQL'
                (mode = 'single' AND product_organization_id IS NOT NULL)
                OR
                (mode IN ('unstarted', 'legacy_multi', 'review_required') AND product_organization_id IS NULL)
                SQL;
            if (DB::getDriverName() === 'sqlite') {
                $sqliteValidModeAndOrganization = str_replace(
                    ['mode', 'product_organization_id'],
                    ['NEW.mode', 'NEW.product_organization_id'],
                    $validModeAndOrganization,
                );
                DB::statement(<<<SQL
                    CREATE TRIGGER product_account_eligibility_mode_org_insert
                    BEFORE INSERT ON product_account_eligibilities
                    WHEN NOT ({$sqliteValidModeAndOrganization})
                    BEGIN
                        SELECT RAISE(ABORT, 'invalid product account eligibility mode');
                    END
                    SQL);
                DB::statement(<<<SQL
                    CREATE TRIGGER product_account_eligibility_mode_org_update
                    BEFORE UPDATE OF mode, product_organization_id ON product_account_eligibilities
                    WHEN NOT ({$sqliteValidModeAndOrganization})
                    BEGIN
                        SELECT RAISE(ABORT, 'invalid product account eligibility mode');
                    END
                    SQL);
            } else {
                DB::statement(<<<SQL
                    ALTER TABLE product_account_eligibilities
                    ADD CONSTRAINT product_account_eligibility_mode_org_check CHECK ({$validModeAndOrganization})
                    SQL);
            }
        }

        if (! Schema::hasTable('product_organization_compatibilities')) {
            Schema::create('product_organization_compatibilities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_account_eligibility_id')
                    ->constrained('product_account_eligibilities')
                    ->restrictOnDelete();
                $table->foreignId('organization_user_id')->constrained('organization_users')->restrictOnDelete();
                $table->timestamp('cutoff_at');
                $table->string('evidence_ref', 255);
                $table->timestamps();

                $table->unique(
                    ['product_account_eligibility_id', 'organization_user_id'],
                    'product_organization_compatibility_unique',
                );
            });
        }
    }

    public function down(): void
    {
        // Product admission rollback means disabling the feature flag. The
        // binding and classification evidence are intentionally retained.
    }
};
