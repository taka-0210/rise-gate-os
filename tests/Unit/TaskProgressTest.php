<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Support\TaskProgress;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class TaskProgressTest extends TestCase
{
    public function test_it_calculates_progress_from_active_tasks(): void
    {
        $progress = TaskProgress::calculate(new Collection([
            new Task(['status' => Task::STATUS_DONE]),
            new Task(['status' => Task::STATUS_IN_PROGRESS]),
            new Task(['status' => Task::STATUS_TODO]),
            new Task(['status' => Task::STATUS_ARCHIVED]),
        ]));

        $this->assertSame('in_progress', $progress['key']);
        $this->assertSame('進行中', $progress['label']);
        $this->assertSame(33, $progress['percentage']);
        $this->assertSame(1, $progress['completed']);
        $this->assertSame(3, $progress['total']);
    }

    public function test_all_active_tasks_done_is_complete(): void
    {
        $progress = TaskProgress::calculate(new Collection([
            new Task(['status' => Task::STATUS_DONE]),
            new Task(['status' => Task::STATUS_DONE]),
        ]));

        $this->assertSame('completed', $progress['key']);
        $this->assertSame(100, $progress['percentage']);
    }

    public function test_no_tasks_is_not_started_at_zero_percent(): void
    {
        $progress = TaskProgress::calculate(new Collection());

        $this->assertSame('not_started', $progress['key']);
        $this->assertSame(0, $progress['percentage']);
    }
}
