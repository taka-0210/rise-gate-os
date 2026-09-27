<?php

declare(strict_types=1);

$database = $argv[1] ?? '';
$root = realpath(dirname(__DIR__, 2));
$real = realpath($database);
if ($root === false || $real === false
    || ! str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root).'/storage/framework/testing/')) {
    fwrite(STDERR, 'Unsafe B-P4 SQLite database.'.PHP_EOL);
    exit(64);
}

$pdo = new PDO('sqlite:'.$real);
$migrationCount = (int) $pdo->query('select count(*) from migrations')->fetchColumn();
$tables = [
    'ai_common_shared_transcript_chunks',
    'ai_common_shared_context_checkpoints',
    'ai_common_shared_checkpoint_dependencies',
    'ai_common_shared_device_cursors',
    'ai_common_shared_session_end_runs',
    'ai_common_shared_session_end_candidates',
];
$quoted = implode(',', array_map(fn (string $table): string => $pdo->quote($table), $tables));
$tableCount = (int) $pdo->query("select count(*) from sqlite_master where type='table' and name in ({$quoted})")->fetchColumn();
$foreignKeys = 0;
foreach ($tables as $table) {
    $foreignKeys += count($pdo->query('pragma foreign_key_list('.$table.')')->fetchAll());
}
$foreignKeyViolations = count($pdo->query('pragma foreign_key_check')->fetchAll());
$integrity = (string) $pdo->query('pragma integrity_check')->fetchColumn();

echo json_encode([
    'migration_count' => $migrationCount,
    'p4_tables' => $tableCount,
    'p4_foreign_keys' => $foreignKeys,
    'foreign_key_violations' => $foreignKeyViolations,
    'integrity' => $integrity,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;

exit($migrationCount === 106 && $tableCount === 6 && $foreignKeys === 19
    && $foreignKeyViolations === 0 && $integrity === 'ok' ? 0 : 1);
