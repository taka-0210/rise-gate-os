<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ProductOrganization\ProductOrganizationInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InventoryProductOrganizations extends Command
{
    protected $signature = 'product-organizations:inventory
        {--apply : Persist classifications and compatibility evidence}
        {--evidence= : Evidence reference prefix}';

    protected $description = 'Dry-run or apply Product Organization eligibility classification.';

    public function handle(ProductOrganizationInventory $inventory): int
    {
        $evidenceRef = trim((string) $this->option('evidence'));
        if ($evidenceRef === '') {
            $evidenceRef = 'pux-a-inventory-'.now()->format('Ymd-His').'-'.Str::lower((string) Str::ulid());
        }
        $report = $inventory->dryRun($evidenceRef);
        if ($this->option('apply')) {
            foreach (User::query()->orderBy('id')->cursor() as $user) {
                $inventory->apply($user, $evidenceRef);
            }
            $report['applied'] = true;
        } else {
            $report['applied'] = false;
        }
        unset($report['rows']);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
