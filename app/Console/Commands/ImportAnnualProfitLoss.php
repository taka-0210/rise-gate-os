<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Company\AnnualProfitLossWorkbookImporter;
use Illuminate\Console\Command;

class ImportAnnualProfitLoss extends Command
{
    protected $signature = 'company-os:import-annual-pl
        {file : 年度別損益計算書.xlsxのパス}
        {--organization= : 会社アカウントのslug。省略時は登録済み1社のみを使用}
        {--through-period= : 取り込む最終期。必ず明示する}';

    protected $description = '年度別損益計算書を会社アカウントの確定実績として取り込む';

    public function handle(AnnualProfitLossWorkbookImporter $importer): int
    {
        $throughPeriod = filter_var(
            $this->option('through-period'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($throughPeriod === false) {
            $this->error('--through-period で取り込む最終期を明示してください。');

            return self::INVALID;
        }

        $organization = $this->organization();
        if (! $organization) {
            return self::INVALID;
        }

        $result = $importer->import(
            (string) $this->argument('file'),
            $organization,
            $throughPeriod,
        );

        $this->info("{$organization->name}へ{$result['imported']}期分を取り込みました。");
        if ($result['skipped'] > 0) {
            $this->line("指定した最終期より後の{$result['skipped']}期分は除外しました。");
        }

        return self::SUCCESS;
    }

    private function organization(): ?Organization
    {
        $slug = $this->option('organization');
        if ($slug) {
            $organization = Organization::query()->where('slug', $slug)->first();
            if (! $organization) {
                $this->error("会社アカウントが見つかりません: {$slug}");
            }

            return $organization;
        }

        if (Organization::query()->count() !== 1) {
            $this->error('会社アカウントが複数あります。--organization でslugを指定してください。');

            return null;
        }

        return Organization::query()->first();
    }
}
