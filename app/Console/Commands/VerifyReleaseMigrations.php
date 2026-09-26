<?php

namespace App\Console\Commands;

use App\Services\Release\MigrationReleaseGate;
use Illuminate\Console\Command;

class VerifyReleaseMigrations extends Command
{
    protected $signature = 'release:migrations:verify {manifest} {--for-apply} {--list}';

    protected $description = 'Read-only verification of the exact approved Migration allowlist.';

    public function handle(MigrationReleaseGate $gate): int
    {
        try {
            $result = $gate->inspect((string) $this->argument('manifest'), (bool) $this->option('for-apply'));
            if ($this->option('list')) {
                foreach ($result['allowed_pending'] as $migration) {
                    $this->line($migration);
                }
            } else {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Migration release gate stopped: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
