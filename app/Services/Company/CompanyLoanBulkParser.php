<?php

namespace App\Services\Company;

use InvalidArgumentException;

class CompanyLoanBulkParser
{
    public const FIELDS = [
        'financial_institution', 'management_number', 'purpose', 'executed_on',
        'term_label', 'original_amount', 'current_balance', 'monthly_principal_payment',
        'annual_interest_rate', 'interest_type', 'recent_interest_amount', 'maturity_on',
        'guarantee_type', 'repayment_day', 'balance_as_of', 'loan_status',
    ];

    public function parse(string $text): array
    {
        $rows = [];
        $lastFinancialInstitution = null;
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $index => $line) {
            if (trim($line) === '') continue;
            $delimiter = str_contains($line, "\t") ? "\t" : ',';
            $values = str_getcsv($line, $delimiter);
            if ($index === 0 && in_array(trim($values[0] ?? ''), ['金融機関', '銀行'], true)) continue;
            if (count($values) !== count(self::FIELDS)) {
                throw new InvalidArgumentException(($index + 1).'行目は16項目で入力してください。');
            }
            $row = array_combine(self::FIELDS, array_map('trim', $values));
            if ($row['financial_institution'] === '') {
                $row['financial_institution'] = $lastFinancialInstitution;
            } else {
                $lastFinancialInstitution = $row['financial_institution'];
            }
            if (! $row['financial_institution']) {
                throw new InvalidArgumentException(($index + 1).'行目の金融機関を入力してください。');
            }
            if ($row['management_number'] === '') {
                throw new InvalidArgumentException(($index + 1).'行目の管理番号を入力してください。');
            }
            foreach (['original_amount', 'current_balance', 'monthly_principal_payment', 'recent_interest_amount'] as $field) {
                $number = str_replace([',', '¥', '￥'], '', $row[$field]);
                if (! preg_match('/^\d+$/', $number)) throw new InvalidArgumentException(($index + 1).'行目の金額に数値でない項目があります。');
                $row[$field] = (int) $number;
            }
            $rate = str_replace('%', '', $row['annual_interest_rate']);
            if ($rate !== '' && (! is_numeric($rate) || $rate < 0 || $rate > 100)) throw new InvalidArgumentException(($index + 1).'行目の金利が正しくありません。');
            $row['annual_interest_rate'] = $rate === '' ? null : (float) $rate;
            if (! in_array($row['interest_type'], ['', 'fixed', 'variable', 'other'], true)) throw new InvalidArgumentException(($index + 1).'行目の金利区分が正しくありません。');
            $row['interest_type'] = $row['interest_type'] ?: null;
            $row['executed_on'] = $this->month($row['executed_on']);
            $row['maturity_on'] = $this->month($row['maturity_on']);
            $row['balance_as_of'] = $row['balance_as_of'] === ''
                ? now()->toDateString()
                : $this->date($row['balance_as_of']);
            if (! in_array($row['loan_status'], ['active', 'completed', 'planned'], true)) {
                throw new InvalidArgumentException(($index + 1).'行目の状態は active・completed・planned のいずれかです。');
            }
            $rows[] = $row;
        }
        if ($rows === []) throw new InvalidArgumentException('取り込めるデータがありません。');
        return $rows;
    }

    private function month(string $value): ?string
    {
        if ($value === '') return null;
        if (! preg_match('/^(\d{4})[-\/年](\d{1,2})/', $value, $matches)) throw new InvalidArgumentException('年月は YYYY-MM 形式で入力してください。');
        return sprintf('%04d-%02d-01', $matches[1], $matches[2]);
    }

    private function date(string $value): ?string
    {
        if ($value === '') return null;
        $normalized = str_replace('/', '-', $value);
        if (! preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $normalized)) throw new InvalidArgumentException('残高基準日は YYYY-MM-DD 形式で入力してください。');
        return $normalized;
    }
}
