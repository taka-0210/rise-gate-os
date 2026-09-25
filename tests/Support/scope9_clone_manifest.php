<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Usage: php scope9_clone_manifest.php <sqlite-path>\n");
    exit(2);
}

$db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$scopeNineTables = ['action_run_settings', 'action_schedule_revisions', 'action_executions', 'action_execution_events'];
$tables = $db->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")
    ->fetchAll(PDO::FETCH_COLUMN);
$legacyTables = array_values(array_diff($tables, array_merge($scopeNineTables, ['migrations'])));
$counts = [];
$hashes = [];
foreach ($legacyTables as $table) {
    if (! preg_match('/^[a-z0-9_]+$/i', $table)) {
        throw new RuntimeException('Unsafe table name in SQLite manifest.');
    }
    $columns = $db->query('pragma table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    $order = in_array('id', $columnNames, true) ? ' order by id' : '';
    $rows = $db->query('select * from '.$table.$order)->fetchAll(PDO::FETCH_ASSOC);
    $counts[$table] = count($rows);
    $hashes[$table] = hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$scopeNineCounts = [];
foreach ($scopeNineTables as $table) {
    $scopeNineCounts[$table] = in_array($table, $tables, true)
        ? (int) $db->query('select count(*) from '.$table)->fetchColumn()
        : null;
}

echo json_encode([
    'integrity' => $db->query('pragma integrity_check')->fetchColumn(),
    'legacy_counts' => $counts,
    'legacy_hash' => hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES)),
    'scope9_tables' => $scopeNineCounts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
