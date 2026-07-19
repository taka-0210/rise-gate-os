<?php

namespace App\Services;

use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ScheduleIntegrityService
{
    public const STATUS_OK = 'ok';
    public const STATUS_MISSING = 'missing';
    public const STATUS_INVALID = 'invalid';

    public function inspect(Project $project): array
    {
        $project->loadMissing(['roadmaps.improvements.tasks', 'improvements.tasks']);

        $missing = collect();
        $invalid = collect();

        if (! $project->start_date || ! $project->due_date) {
            $missing->push('Projectの開始予定日または期限が未設定です。');
        }

        foreach ($project->roadmaps as $roadmap) {
            if (! $roadmap->planned_start_date || ! $roadmap->target_date) {
                $missing->push("ロードマップ「{$roadmap->title}」の期間が未設定です。");
            } elseif ($project->start_date && $project->due_date && ! $this->within(
                $roadmap->planned_start_date,
                $roadmap->target_date,
                $project->start_date,
                $project->due_date
            )) {
                $invalid->push("ロードマップ「{$roadmap->title}」がProjectの期間外です。");
            }

            foreach ($roadmap->improvements as $improvement) {
                if (! $improvement->planned_start_date || ! $improvement->target_date) {
                    $missing->push("取り組み「{$improvement->title}」の期間が未設定です。");
                } elseif ($roadmap->planned_start_date && $roadmap->target_date && ! $this->within(
                    $improvement->planned_start_date,
                    $improvement->target_date,
                    $roadmap->planned_start_date,
                    $roadmap->target_date
                )) {
                    $invalid->push("取り組み「{$improvement->title}」がロードマップの期間外です。");
                }

                foreach ($improvement->tasks as $task) {
                    if (! $task->due_date) {
                        $missing->push("タスク「{$task->title}」の期限が未設定です。");
                    } elseif ($improvement->planned_start_date && $improvement->target_date
                        && ! $task->due_date->betweenIncluded($improvement->planned_start_date, $improvement->target_date)) {
                        $invalid->push("タスク「{$task->title}」の期限が取り組みの期間外です。");
                    }
                }
            }
        }

        foreach ($project->improvements->whereNull('roadmap_id') as $improvement) {
            $missing->push("取り組み「{$improvement->title}」にロードマップが設定されていません。");
        }

        return [
            'status' => $invalid->isNotEmpty() ? self::STATUS_INVALID : ($missing->isNotEmpty() ? self::STATUS_MISSING : self::STATUS_OK),
            'label' => $invalid->isNotEmpty() ? '再設定必要' : ($missing->isNotEmpty() ? '要確認' : '整合性OK'),
            'missing' => $missing->unique()->values(),
            'invalid' => $invalid->unique()->values(),
            'issue_count' => $missing->unique()->count() + $invalid->unique()->count(),
        ];
    }

    public function within(CarbonInterface $start, CarbonInterface $end, CarbonInterface $parentStart, CarbonInterface $parentEnd): bool
    {
        return $start->gte($parentStart) && $end->lte($parentEnd);
    }
}
