<?php

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Usage: php scope8_clone_manifest.php <sqlite-path>\n");
    exit(2);
}

$db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = ['users', 'organizations', 'organization_users', 'workspaces', 'workspace_members', 'projects', 'project_members', 'roadmaps', 'improvements', 'tasks'];
$result = [];
foreach ($tables as $table) {
    $result[$table] = (int) $db->query('select count(*) from '.$table)->fetchColumn();
}
$result['relation_hash'] = hash('sha256', json_encode($db->query('select id, organization_id, owning_workspace_id, owner_user_id from projects order by id')->fetchAll(PDO::FETCH_NUM)));
$result['actor_hash'] = hash('sha256', json_encode([
    $db->query('select id, created_by from roadmaps order by id')->fetchAll(PDO::FETCH_NUM),
    $db->query('select id, proposed_by from improvements order by id')->fetchAll(PDO::FETCH_NUM),
    $db->query('select id, created_by from tasks order by id')->fetchAll(PDO::FETCH_NUM),
]));
$result['scope8_tables'] = (int) $db->query("select count(*) from sqlite_master where type = 'table' and name in ('project_member_roles', 'project_group_audiences', 'project_execution_events')")->fetchColumn();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
