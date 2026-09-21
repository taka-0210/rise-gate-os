<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductOrganizationConcurrencyTest extends TestCase
{
    public static function combinations(): array
    {
        return [
            'S4 x S4' => ['s4', 's4'],
            'S6 x S6' => ['s6', 's6'],
            'S4 x S6' => ['s4', 's6'],
            'S6 x S4 inverse' => ['s6', 's4'],
            'SA x S4' => ['sa', 's4'],
            'SA x S6' => ['sa', 's6'],
            'SA x SA retry boundary' => ['sa', 'sa'],
        ];
    }

    #[DataProvider('combinations')]
    public function test_independent_connections_converge_to_one_product_organization(string $first, string $second): void
    {
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'company-os-pux-a-concurrency-'.bin2hex(random_bytes(6));
        $database = $prefix.'.sqlite';
        $go = $prefix.'.go';
        $readyA = $prefix.'.a.ready';
        $readyB = $prefix.'.b.ready';
        try {
            $seed = $this->runWorker(['seed', $database]);
            $ids = json_decode($seed['stdout'], true, flags: JSON_THROW_ON_ERROR);
            $processA = $this->start($this->arguments($first, $database, $ids, 0, $readyA, $go, 'A'));
            $processB = $this->start($this->arguments($second, $database, $ids, 1, $readyB, $go, 'B'));
            $this->waitForFiles([$readyA, $readyB]);
            touch($go);
            $resultA = $this->finish($processA);
            $resultB = $this->finish($processB);
            $outcomes = [
                json_decode($resultA['stdout'], true, flags: JSON_THROW_ON_ERROR),
                json_decode($resultB['stdout'], true, flags: JSON_THROW_ON_ERROR),
            ];
            $this->assertSame(1, collect($outcomes)->where('status', 'success')->count(), json_encode($outcomes));
            $this->assertSame(1, collect($outcomes)->where('status', 'rejected')->count(), json_encode($outcomes));

            $inspect = $this->runWorker(['inspect', $database, (string) $ids['user_id']]);
            $state = json_decode($inspect['stdout'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('single', $state['mode']);
            $this->assertSame(1, $state['membership_count']);
            $this->assertSame(1, $state['binding_audit_count']);
            $this->assertNotNull($state['product_organization_id']);
            $winner = collect($outcomes)->firstWhere('status', 'success');
            $winningEntry = $winner['worker'] === 'A' ? $first : $second;
            $this->assertSame($winningEntry === 's6' ? 3 : 2, $state['organization_count']);
        } finally {
            foreach ([$go, $readyA, $readyB, $database] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_same_existing_organization_retry_is_idempotent_across_independent_connections(): void
    {
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'company-os-pux-a-concurrency-'.bin2hex(random_bytes(6));
        $database = $prefix.'.sqlite';
        $go = $prefix.'.go';
        $readyA = $prefix.'.a.ready';
        $readyB = $prefix.'.b.ready';
        $readyRetry = $prefix.'.retry.ready';
        try {
            $seed = $this->runWorker(['seed', $database]);
            $ids = json_decode($seed['stdout'], true, flags: JSON_THROW_ON_ERROR);
            $processA = $this->start($this->arguments('s4', $database, $ids, 0, $readyA, $go, 'A'));
            $processB = $this->start($this->arguments('s4', $database, $ids, 0, $readyB, $go, 'B'));
            $this->waitForFiles([$readyA, $readyB]);
            touch($go);
            $outcomes = [
                json_decode($this->finish($processA)['stdout'], true, flags: JSON_THROW_ON_ERROR),
                json_decode($this->finish($processB)['stdout'], true, flags: JSON_THROW_ON_ERROR),
            ];

            $this->assertSame(1, collect($outcomes)->where('status', 'success')->count(), json_encode($outcomes));
            $this->assertSame(1, collect($outcomes)->where('status', 'rejected')->count(), json_encode($outcomes));

            $retry = $this->runWorker(
                $this->arguments('s4', $database, $ids, 0, $readyRetry, $go, 'retry'),
            );
            $retryOutcome = json_decode($retry['stdout'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('success', $retryOutcome['status'], json_encode($retryOutcome));
            $inspect = $this->runWorker(['inspect', $database, (string) $ids['user_id']]);
            $state = json_decode($inspect['stdout'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('single', $state['mode']);
            $this->assertSame($ids['organization_ids'][0], $state['product_organization_id']);
            $this->assertSame(1, $state['membership_count']);
            $this->assertSame(1, $state['binding_audit_count']);
            $this->assertSame(2, $state['organization_count']);
        } finally {
            foreach ([$go, $readyA, $readyB, $readyRetry, $database] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function arguments(string $entry, string $database, array $ids, int $target, string $ready, string $go, string $worker): array
    {
        $new = $entry === 's6';

        return [
            $new ? 'admit-new' : 'admit-existing',
            $database,
            (string) $ids['user_id'],
            $new ? '0' : (string) $ids['organization_ids'][$target],
            match ($entry) {
                's4' => 'staff_invitation_accept',
                's6' => 'owner_onboarding_complete',
                default => 'system_admin_workspace_membership',
            },
            $ready,
            $go,
            $worker,
        ];
    }

    private function start(array $arguments): array
    {
        $command = array_merge(
            [PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/product_organization_concurrency_worker.php')],
            $arguments,
        );
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    private function finish(array $running): array
    {
        [$process, $pipes] = $running;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return compact('stdout', 'stderr', 'exit');
    }

    private function runWorker(array $arguments): array
    {
        return $this->finish($this->start($arguments));
    }

    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail('Concurrency workers did not reach the shared barrier.');
    }
}
