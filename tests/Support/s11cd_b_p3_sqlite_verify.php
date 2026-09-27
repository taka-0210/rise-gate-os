<?php

declare(strict_types=1);

$database = $argv[1] ?? '';
$root = realpath(dirname(__DIR__, 2));
$real = realpath($database);
if ($root === false || $real === false
    || ! str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root).'/storage/framework/testing/')) {
    fwrite(STDERR, 'Unsafe B-P3 SQLite database.'.PHP_EOL);
    exit(64);
}

$pdo = new PDO('sqlite:'.$real);
$migrationCount = (int) $pdo->query('select count(*) from migrations')->fetchColumn();
$tables = [
    'ai_common_shared_sessions', 'ai_common_shared_session_participants',
    'ai_common_shared_session_consents', 'ai_common_shared_capture_streams',
    'ai_common_shared_audio_windows', 'ai_common_shared_transcript_segments',
    'ai_common_shared_transcript_revisions', 'ai_common_shared_speaker_relations',
    'ai_common_shared_identity_revisions',
];
$quoted = implode(',', array_map(fn (string $table): string => $pdo->quote($table), $tables));
$p3TableCount = (int) $pdo->query('select count(*) from sqlite_master where type=\'table\' and name in ('.$quoted.')')->fetchColumn();
$foreignKeyViolations = count($pdo->query('pragma foreign_key_check')->fetchAll());
$integrity = (string) $pdo->query('pragma integrity_check')->fetchColumn();

echo json_encode([
    'migration_count' => $migrationCount,
    'p3_tables' => $p3TableCount,
    'foreign_key_violations' => $foreignKeyViolations,
    'integrity' => $integrity,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;

exit($migrationCount === 105 && $p3TableCount === 9 && $foreignKeyViolations === 0 && $integrity === 'ok' ? 0 : 1);
