<?php

namespace App\Console\Commands;

use App\Services\Release\ProductionReadOnlyAudit;
use Illuminate\Console\Command;

class AuditProductionCurrentState extends Command
{
    protected $signature = 'release:audit-r0 {--confirm-read-only=}';

    protected $description = 'Collect sanitized G01/G02 current-state evidence using SELECT and metadata reads only.';

    public function handle(ProductionReadOnlyAudit $audit): int
    {
        if ((string) $this->option('confirm-read-only') !== 'IR1-R0-READ-ONLY') {
            $this->error('Explicit IR-1 R0 read-only confirmation is required.');

            return self::INVALID;
        }

        try {
            $this->line(json_encode($audit->collect(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('R0 audit stopped: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
