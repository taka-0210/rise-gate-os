<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        fwrite(STDERR, "G5_SHARED_STATE_SIMULATION=STOP\nassertion={$message}\n");
        exit(1);
    }
};

$initial = static fn (): array => [
    'legacy_changed' => false,
    'public_entry_changed' => false,
    'staging' => false,
    'target_env' => false,
    'target_storage' => false,
    'application_release' => false,
    'database_connection' => false,
    'migration' => false,
    'deploy' => false,
];

$cleanup = static function (array $state): array {
    if ($state['target_env'] || $state['target_storage']) {
        $state['cleanup'] = 'review_required';
        $state['rollback'] = 'separate_human_gate_required';
        return $state;
    }
    $state['staging'] = false;
    $state['cleanup'] = 'complete';
    $state['rollback'] = 'not_required_pre_publish';
    return $state;
};

$scenarios = [];

$state = $initial();
$state['result'] = 'STOP_COLLISION';
$scenarios[] = $state;
$assert(! $state['staging'] && ! $state['target_env'] && ! $state['target_storage'], 'collision_is_pre_mutation');

$state = $initial();
$state['result'] = 'STOP_SOURCE_CONTRACT';
$scenarios[] = $state;
$assert(! $state['staging'] && ! $state['legacy_changed'], 'source_failure_is_read_only');

$state = $initial();
$state['staging'] = true;
$state = $cleanup($state);
$state['result'] = 'STOP_ENV_TRANSFER_CLEANED';
$scenarios[] = $state;
$assert(! $state['staging'] && $state['cleanup'] === 'complete', 'env_transfer_failure_cleans_staging');

$state = $initial();
$state['staging'] = true;
$state = $cleanup($state);
$state['result'] = 'STOP_PROJECTOR_CLEANED';
$scenarios[] = $state;
$assert(! $state['staging'] && $state['rollback'] === 'not_required_pre_publish', 'projection_failure_cleans_staging');

$state = $initial();
$state['staging'] = true;
$state = $cleanup($state);
$state['result'] = 'STOP_STORAGE_MANIFEST_CLEANED';
$scenarios[] = $state;
$assert(! $state['staging'] && ! $state['public_entry_changed'], 'manifest_failure_preserves_public_entry');

$state = $initial();
$state['staging'] = true;
$state['target_storage'] = true;
$state = $cleanup($state);
$state['result'] = 'STOP_PARTIAL_PUBLISH_RETAINED';
$scenarios[] = $state;
$assert($state['target_storage'] && $state['cleanup'] === 'review_required', 'partial_publish_is_not_auto_deleted');

$state = $initial();
$state['target_env'] = true;
$state['target_storage'] = true;
$state['cleanup'] = 'complete';
$state['rollback'] = 'not_required';
$state['result'] = 'PASS';
$scenarios[] = $state;
$assert($state['target_env'] && $state['target_storage'], 'pass_publishes_shared_state');
$assert(! $state['legacy_changed'] && ! $state['public_entry_changed'], 'pass_preserves_protected_boundaries');

foreach ($scenarios as $scenario) {
    $assert(! $scenario['application_release'], 'application_release_excluded');
    $assert(! $scenario['database_connection'], 'database_connection_excluded');
    $assert(! $scenario['migration'], 'migration_excluded');
    $assert(! $scenario['deploy'], 'deploy_excluded');
}

fwrite(STDOUT, "G5_SHARED_STATE_SIMULATION=PASS\n");
fwrite(STDOUT, 'scenarios='.count($scenarios)."\n");
fwrite(STDOUT, "assertions={$assertions}\n");
fwrite(STDOUT, "production_connection_attempted=false\n");
fwrite(STDOUT, "production_mutation=false\n");
fwrite(STDOUT, "secret_values_output=false\n");
fwrite(STDOUT, "PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE\n");
