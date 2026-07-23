<?php

namespace App\Services\Company;

use App\Models\CompanyFinancialPeriod;
use App\Models\Organization;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class AnnualProfitLossWorkbookImporter
{
    /**
     * @return array{imported:int, skipped:int}
     */
    public function import(
        string $path,
        Organization $organization,
        int $throughPeriod,
        string $status = CompanyFinancialPeriod::STATUS_ACTUAL,
    ): array {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Excelファイルを読み取れません: {$path}");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Excelファイルを開けませんでした。');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $worksheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($worksheetXml === false) {
                throw new RuntimeException('Excelの先頭シートを確認できませんでした。');
            }

            $rows = $this->rows($worksheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }

        $imported = 0;
        $skipped = 0;
        $sourceHash = hash_file('sha256', $path);

        foreach ($rows as $row) {
            $periodNumber = $this->periodNumber($row['A'] ?? null);
            $fiscalYear = $this->integer($row['B'] ?? null);

            if ($periodNumber === null || $fiscalYear === null) {
                continue;
            }

            if ($periodNumber > $throughPeriod) {
                $skipped++;
                continue;
            }

            CompanyFinancialPeriod::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'fiscal_year' => $fiscalYear,
                    'status' => $status,
                ],
                [
                    'period_number' => $periodNumber,
                    'net_sales' => $this->integer($row['C'] ?? null),
                    'cost_of_sales' => $this->integer($row['D'] ?? null),
                    'cost_ratio' => $this->decimal($row['E'] ?? null),
                    'gross_profit' => $this->integer($row['F'] ?? null),
                    'gross_profit_ratio' => $this->decimal($row['G'] ?? null),
                    'selling_general_admin_expenses' => $this->integer($row['H'] ?? null),
                    'sga_ratio' => $this->decimal($row['I'] ?? null),
                    'operating_profit' => $this->integer($row['J'] ?? null),
                    'operating_profit_ratio' => $this->percentageAsRatio($row['K'] ?? null),
                    'non_operating_income' => $this->integer($row['L'] ?? null),
                    'non_operating_expenses' => $this->integer($row['M'] ?? null),
                    'ordinary_profit' => $this->integer($row['N'] ?? null),
                    'extraordinary_income' => $this->integer($row['O'] ?? null),
                    'extraordinary_losses' => $this->integer($row['P'] ?? null),
                    'profit_before_tax' => $this->integer($row['Q'] ?? null),
                    'income_taxes' => $this->integer($row['R'] ?? null),
                    'net_income' => $this->integer($row['S'] ?? null),
                    'source_filename' => basename($path),
                    'source_hash' => $sourceHash,
                    'imported_at' => now(),
                ],
            );
            $imported++;
        }

        return compact('imported', 'skipped');
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $document = $this->xml($xml);
        $values = [];

        foreach ($document->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                $text .= (string) $part;
            }
            $values[] = $text;
        }

        return $values;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<array<string, string|null>>
     */
    private function rows(string $worksheetXml, array $sharedStrings): array
    {
        $document = $this->xml($worksheetXml);
        $rows = [];

        foreach ($document->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
            $values = [];
            foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                if (! preg_match('/^([A-Z]+)/', $reference, $matches)) {
                    continue;
                }

                $valueNode = $cell->xpath('./*[local-name()="v"]');
                $value = $valueNode ? (string) $valueNode[0] : null;
                if ((string) $cell['t'] === 's' && $value !== null) {
                    $value = $sharedStrings[(int) $value] ?? null;
                }

                $values[$matches[1]] = $value;
            }

            $rows[] = $values;
        }

        return $rows;
    }

    private function xml(string $xml): SimpleXMLElement
    {
        $document = simplexml_load_string($xml);
        if ($document === false) {
            throw new RuntimeException('Excel内のXMLを解析できませんでした。');
        }

        return $document;
    }

    private function periodNumber(?string $value): ?int
    {
        if ($value === null || ! preg_match('/(\\d+)/u', $value, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function integer(?string $value): ?int
    {
        return $value === null || $value === '' ? null : (int) round((float) $value);
    }

    private function decimal(?string $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function percentageAsRatio(?string $value): ?float
    {
        return $value === null || $value === '' ? null : ((float) $value / 100);
    }
}
