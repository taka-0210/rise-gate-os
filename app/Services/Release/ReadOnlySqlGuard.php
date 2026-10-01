<?php

namespace App\Services\Release;

use Illuminate\Database\Connection;

class ReadOnlySqlGuard
{
    /** @var array<string, int> */
    private array $statementCounts = [];

    private int $totalStatements = 0;

    public function install(Connection $connection): void
    {
        $connection->beforeExecuting(function (string $query): void {
            $statementClass = $this->classify($query);

            if ($statementClass === null) {
                throw new R0AuditSafetyException('R0_SQL_NOT_READ_ONLY', 'database_query_guard');
            }

            $this->totalStatements++;
            $this->statementCounts[$statementClass] = ($this->statementCounts[$statementClass] ?? 0) + 1;
        });
    }

    /** @return array{result: string, allowed_statement_classes: list<string>, statement_counts: array<string, int>, total_statements: int, rejected_statements: int} */
    public function evidence(): array
    {
        ksort($this->statementCounts);

        return [
            'result' => 'PASS',
            'allowed_statement_classes' => ['DESCRIBE', 'PRAGMA', 'SELECT', 'SHOW'],
            'statement_counts' => $this->statementCounts,
            'total_statements' => $this->totalStatements,
            'rejected_statements' => 0,
        ];
    }

    private function classify(string $query): ?string
    {
        $query = preg_replace('/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|\/\*.*?\*\/)+/s', '', $query) ?? '';
        if (! preg_match('/\A([A-Za-z]+)/', $query, $matches)) {
            return null;
        }

        $statement = strtoupper($matches[1]);

        return match ($statement) {
            'SELECT' => 'SELECT',
            'PRAGMA' => 'PRAGMA',
            'SHOW' => 'SHOW',
            'DESCRIBE', 'DESC' => 'DESCRIBE',
            default => null,
        };
    }
}
