<?php

namespace App\Console\Commands;

use App\Services\ActionExecution\ActionExecutionWriter;
use Illuminate\Console\Command;

class CatchUpActionExecutions extends Command
{
    protected $signature = 'actions:catch-up {--organization=}';
    protected $description = 'Generate bounded Action executions and mark elapsed windows missed';

    public function handle(ActionExecutionWriter $writer): int
    {
        $result = $writer->catchUp($this->option('organization') ? (int) $this->option('organization') : null);
        $this->info("generated={$result['generated']} missed={$result['missed']}");
        return self::SUCCESS;
    }
}
