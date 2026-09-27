<?php

declare(strict_types=1);

$database = $argv[1] ?? '';
$root = realpath(dirname(__DIR__, 2));
$real = realpath($database);
if ($root === false || $real === false
    || ! str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root).'/storage/framework/testing/')) {
    fwrite(STDERR, "Unsafe B-P2 SQLite database.\n");
    exit(64);
}

$pdo = new PDO('sqlite:'.$real);
$migrationCount = (int) $pdo->query('select count(*) from migrations')->fetchColumn();
$p2TableCount = (int) $pdo->query(
    "select count(*) from sqlite_master where type='table' and name in (".
    "'ai_common_shared_source_contexts','ai_common_shared_ai_requests',".
    "'ai_common_shared_co_states','ai_common_shared_proposal_contexts')"
)->fetchColumn();
$foreignKeyViolations = count($pdo->query('pragma foreign_key_check')->fetchAll());
$integrity = (string) $pdo->query('pragma integrity_check')->fetchColumn();

echo json_encode([
    'migration_count' => $migrationCount,
    'p2_tables' => $p2TableCount,
    'foreign_key_violations' => $foreignKeyViolations,
    'integrity' => $integrity,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;

exit($migrationCount === 104 && $p2TableCount === 4 && $foreignKeyViolations === 0 && $integrity === 'ok' ? 0 : 1);
