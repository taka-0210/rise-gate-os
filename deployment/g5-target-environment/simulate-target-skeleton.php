<?php

declare(strict_types=1);

function assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "G5_SKELETON_SIMULATION=STOP\nsafe_error_code={$message}\n");
        exit(1);
    }
}

function simulate_build(array $state, ?string $failure = null): array
{
    if ($state['topology'] !== 'absent' || $state['staging'] !== 'absent') {
        return $state + ['result' => 'STOP_PRECONDITION'];
    }
    if ($state['public_entries'] !== ['.user.ini', 'default_page.png', 'index.html']) {
        return $state + ['result' => 'STOP_PUBLIC_BOUNDARY'];
    }

    $beforePublic = $state['public_entries'];
    $state['staging'] = ['releases' => [], 'shared' => []];
    if ($failure === 'before_publish') {
        $state['staging'] = 'absent';

        return $state + ['result' => 'STOP_CLEANED'];
    }

    $state['topology'] = $state['staging'];
    $state['staging'] = 'absent';
    if ($failure === 'after_publish') {
        $state['topology'] = 'absent';

        return $state + ['result' => 'STOP_ROLLED_BACK'];
    }

    $state['result'] = 'PASS';
    $state['public_unchanged'] = $state['public_entries'] === $beforePublic;

    return $state;
}

$base = [
    'topology' => 'absent',
    'staging' => 'absent',
    'public_entries' => ['.user.ini', 'default_page.png', 'index.html'],
    'legacy_changed' => false,
    'env_created' => false,
    'storage_created' => false,
    'application_created' => false,
    'current_created' => false,
];

$pass = simulate_build($base);
assert_true($pass['result'] === 'PASS', 'PASS_PATH_FAILED');
assert_true(array_keys($pass['topology']) === ['releases', 'shared'], 'TOPOLOGY_SET_MISMATCH');
assert_true($pass['topology']['releases'] === [] && $pass['topology']['shared'] === [], 'SKELETON_NOT_EMPTY');
assert_true($pass['public_unchanged'] === true, 'PUBLIC_ENTRY_CHANGED');
assert_true(! $pass['env_created'] && ! $pass['storage_created'], 'SHARED_STATE_CREATED');
assert_true(! $pass['application_created'] && ! $pass['current_created'], 'APPLICATION_OR_CURRENT_CREATED');

$preexisting = $base;
$preexisting['topology'] = ['unexpected' => []];
assert_true(simulate_build($preexisting)['result'] === 'STOP_PRECONDITION', 'PREEXISTING_TOPOLOGY_NOT_REJECTED');

$staging = $base;
$staging['staging'] = ['residual' => []];
assert_true(simulate_build($staging)['result'] === 'STOP_PRECONDITION', 'PREEXISTING_STAGING_NOT_REJECTED');

$wrongPublic = $base;
$wrongPublic['public_entries'] = ['index.html'];
assert_true(simulate_build($wrongPublic)['result'] === 'STOP_PUBLIC_BOUNDARY', 'PUBLIC_BOUNDARY_NOT_REJECTED');

$beforePublish = simulate_build($base, 'before_publish');
assert_true($beforePublish['result'] === 'STOP_CLEANED' && $beforePublish['staging'] === 'absent', 'PRE_PUBLISH_CLEANUP_FAILED');
assert_true($beforePublish['topology'] === 'absent', 'PRE_PUBLISH_TOPOLOGY_MUTATED');

$afterPublish = simulate_build($base, 'after_publish');
assert_true($afterPublish['result'] === 'STOP_ROLLED_BACK', 'POST_PUBLISH_ROLLBACK_FAILED');
assert_true($afterPublish['topology'] === 'absent' && $afterPublish['staging'] === 'absent', 'POST_PUBLISH_RESIDUAL');

fwrite(STDOUT, "G5_TARGET_SKELETON_SIMULATION=PASS\nscenarios=6\nassertions=13\nproduction_connection_attempted=false\nproduction_mutation=false\n");
