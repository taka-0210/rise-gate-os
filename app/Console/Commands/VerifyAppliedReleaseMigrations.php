<?php

namespace App\Console\Commands;

use App\Services\Release\MigrationReleaseGate;
use Illuminate\Console\Command;

class VerifyAppliedReleaseMigrations extends Command
{
    protected $signature = 'release:migrations:verify-applied {manifest}';

    protected $description = 'Fail unless every approved Migration is now in the ledger and no unapproved pending Migration remains.';

    public function handle(MigrationReleaseGate $gate): int
    {
        try {
            $result = $gate->verifyApplied((string) $this->argument('manifest'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Migration postflight stopped: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
