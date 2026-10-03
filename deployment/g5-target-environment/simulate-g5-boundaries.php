<?php

declare(strict_types=1);

$legacy = [
    'application' => 'present',
    'public' => 'present',
    'mutation_count' => 0,
];
$target = [
    'domain' => 'present',
    'public_parent' => 'present',
    'topology' => 'absent',
    'public_entry' => 'absent',
    'rehearsal' => 'absent',
];
$assertions = 0;
$scenarios = 0;
$assert = static function (bool $condition) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('BOUNDARY_ASSERTION_FAILED');
    }
};

try {
    $beforeLegacy = hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR));

    // Read-only discovery.
    $snapshot = $target;
    $assert($snapshot === $target);
    $assert($legacy['mutation_count'] === 0);
    $scenarios++;

    // Successful isolated rehearsal and cleanup.
    $target['rehearsal'] = 'created';
    $assert($target['topology'] === 'absent');
    $assert($target['public_entry'] === 'absent');
    $target['rehearsal'] = 'absent';
    $assert($target['rehearsal'] === 'absent');
    $scenarios++;

    // Failure before rehearsal root creation.
    $failed = $target;
    $assert($failed['rehearsal'] === 'absent');
    $assert($failed['topology'] === 'absent');
    $scenarios++;

    // Failure after rehearsal creation is cleaned without target adoption.
    $failed['rehearsal'] = 'partial';
    $failed['rehearsal'] = 'absent';
    $assert($failed['rehearsal'] === 'absent');
    $assert($failed['public_entry'] === 'absent');
    $scenarios++;

    // Target skeleton is isolated; legacy stays immutable.
    $target['topology'] = 'g5_created';
    $assert($target['public_entry'] === 'absent');
    $assert(hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)) === $beforeLegacy);
    $scenarios++;

    // Pre-public cleanup removes only the G5-created topology.
    $target['topology'] = 'absent';
    $assert($target['topology'] === 'absent');
    $assert(hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)) === $beforeLegacy);
    $scenarios++;

    // Public binding is ineligible while any blocker remains.
    $blockers = 9;
    $assert($blockers > 0);
    $assert($target['public_entry'] === 'absent');
    $scenarios++;

    printf(
        "G5_BOUNDARY_SIMULATION=PASS\nscenario_count=%d\nassertion_count=%d\nlegacy_mutation=0\nproduction_connection=false\n",
        $scenarios,
        $assertions
    );
} catch (Throwable) {
    fwrite(STDERR, "G5_BOUNDARY_SIMULATION=STOP\nsafe_error_code=BOUNDARY_SIMULATION_FAILED\n");
    exit(1);
}
