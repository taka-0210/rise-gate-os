<?php

declare(strict_types=1);

final class G4Topology
{
    /** @var array<string, array{sha: string, verified: bool}> */
    public array $releases = [];
    public ?string $current = null;
    public ?string $previous = null;
    public string $publicTarget = 'current/public';

    public function install(string $releaseId, string $sha, bool $artifactValid): void
    {
        if (!$artifactValid) {
            throw new RuntimeException('ARTIFACT_SHA256_MISMATCH');
        }
        if (isset($this->releases[$releaseId])) {
            throw new RuntimeException('RELEASE_ALREADY_EXISTS');
        }
        $this->releases[$releaseId] = ['sha' => $sha, 'verified' => true];
    }

    public function switchTo(string $releaseId, ?string $failAt = null): void
    {
        $this->requireVerified($releaseId);
        if ($this->current === null || $this->current === $releaseId) {
            throw new RuntimeException('SWITCH_PRECONDITION_FAILED');
        }
        $old = $this->current;
        if ($failAt === 'before_previous_rename') {
            throw new RuntimeException('INJECTED_BEFORE_PREVIOUS_RENAME');
        }
        $this->previous = $old;
        if ($failAt === 'before_current_rename') {
            throw new RuntimeException('INJECTED_BEFORE_CURRENT_RENAME');
        }
        $this->current = $releaseId;
    }

    public function rollback(string $expectedCandidate, ?string $failAt = null): void
    {
        if ($this->current !== $expectedCandidate || $this->previous === null) {
            throw new RuntimeException('ROLLBACK_IDENTITY_MISMATCH');
        }
        $this->requireVerified($this->current);
        $this->requireVerified($this->previous);
        $oldCurrent = $this->current;
        $rollbackTarget = $this->previous;
        if ($failAt === 'before_previous_rename') {
            throw new RuntimeException('INJECTED_BEFORE_PREVIOUS_RENAME');
        }
        $this->previous = $oldCurrent;
        if ($failAt === 'before_current_rename') {
            throw new RuntimeException('INJECTED_BEFORE_CURRENT_RENAME');
        }
        $this->current = $rollbackTarget;
    }

    public function snapshot(): string
    {
        return hash('sha256', json_encode([
            'releases' => $this->releases,
            'current' => $this->current,
            'previous' => $this->previous,
            'public' => $this->publicTarget,
        ], JSON_THROW_ON_ERROR));
    }

    private function requireVerified(string $releaseId): void
    {
        if (!isset($this->releases[$releaseId]) || !$this->releases[$releaseId]['verified']) {
            throw new RuntimeException('RELEASE_NOT_VERIFIED');
        }
    }
}

$assertions = 0;
$scenarios = 0;
$expect = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('ASSERTION_FAILED_'.$message);
    }
};
$expectFailure = static function (callable $operation, string $code) use (&$assertions): void {
    try {
        $operation();
    } catch (RuntimeException $exception) {
        $assertions++;
        if ($exception->getMessage() !== $code) {
            throw new RuntimeException('UNEXPECTED_FAILURE_CODE');
        }
        return;
    }
    throw new RuntimeException('EXPECTED_FAILURE_MISSING');
};
$base = static function (): G4Topology {
    $state = new G4Topology();
    $state->releases['legacy'] = ['sha' => str_repeat('a', 40), 'verified' => true];
    $state->current = 'legacy';
    return $state;
};

try {
    $candidate = 'ir1-924af91188cc60d33ff87c91b94ecc1d539566e6';
    $sha = '924af91188cc60d33ff87c91b94ecc1d539566e6';

    $state = $base();
    $state->install($candidate, $sha, true);
    $state->switchTo($candidate);
    $expect($state->current === $candidate, 'switch_current');
    $expect($state->previous === 'legacy', 'switch_previous');
    $expect($state->publicTarget === 'current/public', 'switch_public');
    $state->rollback($candidate);
    $expect($state->current === 'legacy', 'rollback_current');
    $expect($state->previous === $candidate, 'rollback_previous');
    $expect($state->publicTarget === 'current/public', 'rollback_public');
    $scenarios++;

    $state = $base();
    $before = $state->snapshot();
    $expectFailure(fn () => $state->install($candidate, $sha, false), 'ARTIFACT_SHA256_MISMATCH');
    $expect($state->snapshot() === $before, 'bad_artifact_unchanged');
    $scenarios++;

    $state = $base();
    $state->install($candidate, $sha, true);
    $before = $state->snapshot();
    $expectFailure(fn () => $state->install($candidate, $sha, true), 'RELEASE_ALREADY_EXISTS');
    $expect($state->snapshot() === $before, 'existing_release_unchanged');
    $scenarios++;

    $state = $base();
    $state->install($candidate, $sha, true);
    $expectFailure(fn () => $state->switchTo($candidate, 'before_previous_rename'), 'INJECTED_BEFORE_PREVIOUS_RENAME');
    $expect($state->current === 'legacy', 'switch_fail_early_current');
    $expect($state->previous === null, 'switch_fail_early_previous');
    $scenarios++;

    $state = $base();
    $state->install($candidate, $sha, true);
    $expectFailure(fn () => $state->switchTo($candidate, 'before_current_rename'), 'INJECTED_BEFORE_CURRENT_RENAME');
    $expect($state->current === 'legacy', 'switch_fail_late_current');
    $expect($state->previous === 'legacy', 'switch_fail_late_previous_safe');
    $scenarios++;

    $state = $base();
    $state->install($candidate, $sha, true);
    $state->switchTo($candidate);
    $before = $state->snapshot();
    $expectFailure(fn () => $state->rollback('wrong-release'), 'ROLLBACK_IDENTITY_MISMATCH');
    $expect($state->snapshot() === $before, 'rollback_identity_unchanged');
    $scenarios++;

    $state = $base();
    $state->install($candidate, $sha, true);
    $state->switchTo($candidate);
    $expectFailure(fn () => $state->rollback($candidate, 'before_current_rename'), 'INJECTED_BEFORE_CURRENT_RENAME');
    $expect($state->current === $candidate, 'rollback_fail_current_safe');
    $expect($state->previous === $candidate, 'rollback_fail_previous_recoverable');
    $expect($state->publicTarget === 'current/public', 'rollback_fail_public');
    $scenarios++;

    printf(
        "G4_TOPOLOGY_SIMULATION=PASS\nscenario_count=%d\nassertion_count=%d\nproduction_connection=false\nproduction_mutation=false\n",
        $scenarios,
        $assertions
    );
} catch (Throwable $throwable) {
    fwrite(STDERR, "G4_TOPOLOGY_SIMULATION=STOP\nsafe_error_code=SIMULATION_FAILURE\n");
    exit(1);
}
