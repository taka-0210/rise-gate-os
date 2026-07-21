<?php

namespace Tests\Unit;

use App\Models\Improvement;
use App\Models\Task;
use App\Support\EffortProgress;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class EffortProgressTest extends TestCase
{
    public function test_it_weights_progress_by_improvement_effort(): void
    {
        $small = new Improvement(['planned_effort_days' => 2]);
        $small->setRelation('tasks', new Collection([
            new Task(['status' => Task::STATUS_DONE]),
            new Task(['status' => Task::STATUS_TODO]),
        ]));

        $large = new Improvement(['planned_effort_days' => 8]);
        $large->setRelation('tasks', new Collection([
            new Task(['status' => Task::STATUS_DONE]),
        ]));

        $progress = EffortProgress::calculate(new Collection([$small, $large]));

        $this->assertSame('in_progress', $progress['key']);
        $this->assertSame(90, $progress['percentage']);
        $this->assertSame(9.0, $progress['completed']);
        $this->assertSame(10.0, $progress['total']);
        $this->assertSame('人日', $progress['unit']);
    }

    public function test_it_excludes_unset_effort_and_reports_it(): void
    {
        $unset = new Improvement();
        $unset->setRelation('tasks', new Collection([
            new Task(['status' => Task::STATUS_DONE]),
        ]));

        $progress = EffortProgress::calculate(new Collection([$unset]));

        $this->assertSame(0, $progress['percentage']);
        $this->assertSame(1, $progress['unset_effort_count']);
    }
}
