<?php

namespace App\Services\Company;

use InvalidArgumentException;

class AnnualProfitLossBulkParser
{
    public function __construct(private AnnualProfitLossCalculator $calculator) {}

    public function parse(string $text): array
    {
        $rows = preg_split('/\R/u', trim($text)) ?: [];
        $result = [];

        foreach ($rows as $index => $line) {
            if (trim($line) === '') continue;
            $delimiter = str_contains($line, "\t") ? "\t" : ',';
            $values = array_map(fn ($value) => trim(str_replace([',', '¥', '￥'], '', $value)), str_getcsv($line, $delimiter));
            if ($index === 0 && ! is_numeric($values[0] ?? null)) continue;
            if (count($values) !== count(AnnualProfitLossCalculator::INPUT_FIELDS)) {
                throw new InvalidArgumentException(($index + 1).'行目は10項目で入力してください。');
            }
            foreach ($values as $value) {
                if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
                    throw new InvalidArgumentException(($index + 1).'行目に数値でない項目があります。');
                }
            }
            $result[] = $this->calculator->calculate(array_combine(AnnualProfitLossCalculator::INPUT_FIELDS, $values));
        }

        if ($result === []) throw new InvalidArgumentException('取り込めるデータがありません。');
        return $result;
    }
}
