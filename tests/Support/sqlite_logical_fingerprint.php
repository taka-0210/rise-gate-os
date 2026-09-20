<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$databasePath = $argv[2] ?? '';
$baselinePath = $argv[3] ?? null;
$temporaryDirectory = realpath(sys_get_temp_dir());
$databaseDirectory = $databasePath === '' ? false : realpath(dirname($databasePath));
if ($temporaryDirectory === false
    || $databaseDirectory !== $temporaryDirectory
    || ! str_starts_with(basename($databasePath), 'company-os-scope7-migration-')) {
    fwrite(STDERR, "Refusing to inspect a database outside the Scope 7 temporary fixture.\n");
    exit(64);
}

$pdo = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tableNames = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

if ($mode === 'capture') {
    $fingerprint = [];
    foreach ($tableNames as $table) {
        if ($table === 'migrations') {
            continue;
        }
        $fingerprint[$table] = tableFingerprint($pdo, $table);
    }
    echo json_encode(['tables' => $fingerprint], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

if ($mode !== 'compare' || ! $baselinePath || ! is_file($baselinePath)) {
    fwrite(STDERR, "Usage: capture <db> or compare <db> <baseline>.\n");
    exit(64);
}

$baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
$differences = [];
foreach ($baseline['tables'] as $table => $before) {
    if (! in_array($table, $tableNames, true)) {
        $differences[$table] = ['error' => 'missing_table'];

        continue;
    }
    $after = tableFingerprint($pdo, $table, $before['columns']);
    if ($before['count'] !== $after['count'] || ! hash_equals($before['hash'], $after['hash'])) {
        $differences[$table] = ['before' => $before, 'after' => $after];
    }
}

$newTables = array_values(array_diff($tableNames, array_keys($baseline['tables']), ['migrations']));
echo json_encode([
    'preserved' => $differences === [],
    'checked_existing_tables' => count($baseline['tables']),
    'differences' => $differences,
    'new_tables' => $newTables,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
exit($differences === [] ? 0 : 2);

function tableFingerprint(PDO $pdo, string $table, ?array $limitedColumns = null): array
{
    $quotedTable = '"'.str_replace('"', '""', $table).'"';
    $schema = $pdo->query('PRAGMA table_info('.$quotedTable.')')->fetchAll(PDO::FETCH_ASSOC);
    $available = array_column($schema, 'name');
    $columns = $limitedColumns ?? $available;
    foreach ($columns as $column) {
        if (! in_array($column, $available, true)) {
            return ['columns' => $columns, 'count' => -1, 'hash' => 'missing-column:'.$column];
        }
    }
    $quotedColumns = implode(', ', array_map(
        fn (string $column): string => '"'.str_replace('"', '""', $column).'"',
        $columns,
    ));
    $orderColumn = in_array('id', $available, true) ? '"id"' : 'rowid';
    $statement = $pdo->query("SELECT {$quotedColumns} FROM {$quotedTable} ORDER BY {$orderColumn}");
    $context = hash_init('sha256');
    $count = 0;
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        hash_update($context, json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        $count++;
    }

    return ['columns' => $columns, 'count' => $count, 'hash' => hash_final($context)];
}
