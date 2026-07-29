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
            if (! in_array(count($values), [10, 11], true)) {
                throw new InvalidArgumentException(($index + 1).'行目は10項目または11項目で入力してください。');
            }
            foreach ($values as $value) {
                if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
                    throw new InvalidArgumentException(($index + 1).'行目に数値でない項目があります。');
                }
            }
            $fields = AnnualProfitLossCalculator::INPUT_FIELDS;
            if (count($values) === 10) {
                $fields = array_values(array_diff($fields, ['interest_expense']));
            }
            $calculated = $this->calculator->calculate(array_combine($fields, $values));
            if ($calculated['interest_expense'] !== null && $calculated['interest_expense'] > $calculated['non_operating_expenses']) {
                throw new InvalidArgumentException(($index + 1).'行目の支払利息は営業外費用以下で入力してください。');
            }
            $result[] = $calculated;
        }

        if ($result === []) throw new InvalidArgumentException('取り込めるデータがありません。');
        return $result;
    }
}
