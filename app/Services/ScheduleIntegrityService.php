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

        if (! $project->start_date && $project->duration_days) {
            return $this->inspectRelative($project);
        }

        $missing = collect();
        $invalid = collect();
        $unverifiable = collect();
        $entities = [
            'missing' => collect(),
            'invalid' => collect(),
            'unverifiable' => collect(),
        ];

        if (! $project->start_date || ! $project->due_date) {
            $missing->push('Projectの開始予定日または期限が未設定です。');
            $entities['missing']->push('project:'.$project->id);
        }

        foreach ($project->roadmaps as $roadmap) {
            if (! $roadmap->planned_start_date || ! $roadmap->target_date) {
                $missing->push("ロードマップ「{$roadmap->title}」の期間が未設定です。");
                $entities['missing']->push('roadmap:'.$roadmap->id);
            } elseif ($project->start_date && $project->due_date && ! $this->within(
                $roadmap->planned_start_date,
                $roadmap->target_date,
                $project->start_date,
                $project->due_date
            )) {
                $invalid->push("ロードマップ「{$roadmap->title}」がProjectの期間外です。");
                $entities['invalid']->push('roadmap:'.$roadmap->id);
            } elseif (! $project->start_date || ! $project->due_date) {
                $unverifiable->push("ロードマップ「{$roadmap->title}」はProjectの日程未設定により判定できません。");
                $entities['unverifiable']->push('roadmap:'.$roadmap->id);
            }

            foreach ($roadmap->improvements as $improvement) {
                if (! $improvement->planned_start_date || ! $improvement->target_date) {
                    $missing->push("取り組み「{$improvement->title}」の期間が未設定です。");
                    $entities['missing']->push('improvement:'.$improvement->id);
                } elseif ($roadmap->planned_start_date && $roadmap->target_date && ! $this->within(
                    $improvement->planned_start_date,
                    $improvement->target_date,
                    $roadmap->planned_start_date,
                    $roadmap->target_date
                )) {
                    $invalid->push("取り組み「{$improvement->title}」がロードマップの期間外です。");
                    $entities['invalid']->push('improvement:'.$improvement->id);
                } elseif (! $roadmap->planned_start_date || ! $roadmap->target_date) {
                    $unverifiable->push("取り組み「{$improvement->title}」はロードマップの日程未設定により判定できません。");
                    $entities['unverifiable']->push('improvement:'.$improvement->id);
                }

                foreach ($improvement->tasks as $task) {
                    if (! $task->planned_start_date || ! $task->due_date) {
                        $missing->push("タスク「{$task->title}」の期間が未設定です。");
                        $entities['missing']->push('task:'.$task->id);
                    } elseif ($improvement->planned_start_date && $improvement->target_date
                        && ! $this->within($task->planned_start_date, $task->due_date, $improvement->planned_start_date, $improvement->target_date)) {
                        $invalid->push("タスク「{$task->title}」の期間が取り組みの期間外です。");
                        $entities['invalid']->push('task:'.$task->id);
                    } elseif (! $improvement->planned_start_date || ! $improvement->target_date) {
                        $unverifiable->push("タスク「{$task->title}」は取り組みの日程未設定により判定できません。");
                        $entities['unverifiable']->push('task:'.$task->id);
                    }
                }
            }
        }

        foreach ($project->improvements->whereNull('roadmap_id') as $improvement) {
            $missing->push("取り組み「{$improvement->title}」にロードマップが設定されていません。");
            $entities['missing']->push('improvement:'.$improvement->id);
        }

        $counts = fn (Collection $items) => collect(['project', 'roadmap', 'improvement', 'task'])
            ->mapWithKeys(fn (string $type) => [$type => $items->unique()->filter(fn (string $key) => str_starts_with($key, $type.':'))->count()])
            ->all();

        $invalidCount = $entities['invalid']->unique()->count();
        $missingCount = $entities['missing']->unique()->count();
        $remainingCount = $invalid->isNotEmpty() ? $invalidCount : $missingCount;

        return [
            'status' => $invalid->isNotEmpty() ? self::STATUS_INVALID : ($missing->isNotEmpty() ? self::STATUS_MISSING : self::STATUS_OK),
            'label' => $invalid->isNotEmpty()
                ? "日程要再設定：残り{$invalidCount}件"
                : ($missing->isNotEmpty() ? "日程未設定：残り{$missingCount}件" : '整合性OK'),
            'missing' => $missing->unique()->values(),
            'invalid' => $invalid->unique()->values(),
            'unverifiable' => $unverifiable->unique()->values(),
            'entities' => collect($entities)->map(fn (Collection $items) => $items->unique()->values()),
            'counts' => [
                'missing' => $counts($entities['missing']),
                'invalid' => $counts($entities['invalid']),
                'unverifiable' => $counts($entities['unverifiable']),
            ],
            'remaining_count' => $remainingCount,
            'issue_count' => $entities['missing']->merge($entities['invalid'])->merge($entities['unverifiable'])->unique()->count(),
        ];
    }

    public function within(CarbonInterface $start, CarbonInterface $end, CarbonInterface $parentStart, CarbonInterface $parentEnd): bool
    {
        return $start->gte($parentStart) && $end->lte($parentEnd);
    }

    private function inspectRelative(Project $project): array
    {
        $missing = collect();
        $invalid = collect();
        $entities = ['missing' => collect(), 'invalid' => collect(), 'unverifiable' => collect()];
        $check = function ($model, string $type, ?int $start, ?int $end, int $parentStart, int $parentEnd) use ($missing, $invalid, $entities): void {
            $key = $type.':'.$model->id;
            if (! $start || ! $end) {
                $missing->push("{$model->title} の相対期間が未設定です。");
                $entities['missing']->push($key);
            } elseif ($start < $parentStart || $end > $parentEnd || $end < $start) {
                $invalid->push("{$model->title} が上位項目の期間外です。");
                $entities['invalid']->push($key);
            }
        };

        foreach ($project->roadmaps as $roadmap) {
            $check($roadmap, 'roadmap', $roadmap->planned_start_day, $roadmap->target_day, 1, $project->duration_days);
            foreach ($roadmap->improvements as $improvement) {
                $check($improvement, 'improvement', $improvement->planned_start_day, $improvement->target_day, $roadmap->planned_start_day ?? 1, $roadmap->target_day ?? $project->duration_days);
                foreach ($improvement->tasks as $task) {
                    $check($task, 'task', $task->planned_start_day, $task->due_day, $improvement->planned_start_day ?? 1, $improvement->target_day ?? $project->duration_days);
                }
            }
        }

        foreach ($project->improvements->whereNull('roadmap_id') as $improvement) {
            $missing->push("{$improvement->title} にロードマップが設定されていません。");
            $entities['missing']->push('improvement:'.$improvement->id);
        }

        $counts = fn (Collection $items) => collect(['project', 'roadmap', 'improvement', 'task'])
            ->mapWithKeys(fn (string $type) => [$type => $items->unique()->filter(fn (string $key) => str_starts_with($key, $type.':'))->count()])->all();
        $invalidCount = $entities['invalid']->unique()->count();
        $missingCount = $entities['missing']->unique()->count();

        return [
            'status' => $invalid->isNotEmpty() ? self::STATUS_INVALID : ($missing->isNotEmpty() ? self::STATUS_MISSING : self::STATUS_OK),
            'label' => $invalid->isNotEmpty() ? "期間外設定：残り{$invalidCount}件" : ($missing->isNotEmpty() ? "相対日程未設定：残り{$missingCount}件" : '整合性OK'),
            'missing' => $missing->unique()->values(),
            'invalid' => $invalid->unique()->values(),
            'unverifiable' => collect(),
            'entities' => collect($entities)->map(fn (Collection $items) => $items->unique()->values()),
            'counts' => ['missing' => $counts($entities['missing']), 'invalid' => $counts($entities['invalid']), 'unverifiable' => $counts($entities['unverifiable'])],
            'remaining_count' => $invalid->isNotEmpty() ? $invalidCount : $missingCount,
            'issue_count' => $entities['missing']->merge($entities['invalid'])->unique()->count(),
        ];
    }
}
