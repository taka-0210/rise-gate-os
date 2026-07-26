<?php

namespace App\Services;

use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectPlanVersion;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectPlanRestoreService
{
    public function __construct(
        private readonly ProjectPlanSnapshotService $snapshots,
    ) {}

    public function preview(Project $project, ProjectPlanVersion $version): array
    {
        $target = $this->entities($version->plan_snapshot);
        $current = $this->entities($this->snapshots->capture($project->fresh()));

        return [
            'roadmaps' => $this->diffCounts($current['roadmaps'], $target['roadmaps']),
            'improvements' => $this->diffCounts($current['improvements'], $target['improvements']),
            'tasks' => $this->diffCounts($current['tasks'], $target['tasks']),
        ];
    }

    public function restore(Project $project, ProjectPlanVersion $version, User $actor): ProjectPlanVersion
    {
        $snapshot = $version->plan_snapshot;
        $snapshotProjectId = (string) data_get($snapshot, 'project.public_id', '');
        if ($snapshotProjectId === '' || $snapshotProjectId !== $project->public_id) {
            throw new RuntimeException('この保存履歴は対象Projectのものではありません。');
        }

        return DB::transaction(function () use ($project, $version, $actor, $snapshot): ProjectPlanVersion {
            $label = $version->title ?: $version->change_summary ?: 'VERSION '.$version->version_number;
            $this->snapshots->storeManualTimelineVersion(
                $project->fresh(),
                $actor,
                '復元前：'.$label,
                'VERSION '.$version->version_number.' から復元する直前の自動保存',
            );

            $this->applyProject($project, (array) ($snapshot['project'] ?? []));
            $this->applyHierarchy($project->fresh(), $snapshot, $actor);

            return $this->snapshots->storeManualTimelineVersion(
                $project->fresh(),
                $actor,
                '復元：'.$label,
                'VERSION '.$version->version_number.' の予定内容を復元。実績記録と既存項目の進捗状態は維持。',
            );
        });
    }

    private function applyProject(Project $project, array $snapshot): void
    {
        $project->update(Arr::only($snapshot, [
            'start_date', 'due_date', 'duration_days',
        ]));
    }

    private function applyHierarchy(Project $project, array $snapshot, User $actor): void
    {
        $entities = $this->entities($snapshot);
        $this->deleteMissing($project, $entities);

        $roadmapModels = [];
        foreach ($entities['roadmaps'] as $publicId => $data) {
            $roadmapModels[$publicId] = $this->restoreModel(
                Roadmap::withTrashed()->where('project_id', $project->id)->where('public_id', $publicId)->first(),
                new Roadmap(),
                [
                    'public_id' => $publicId,
                    'organization_id' => $project->organization_id,
                    'workspace_id' => $project->owning_workspace_id,
                    'project_id' => $project->id,
                    'created_by' => $actor->id,
                    ...$data['attributes'],
                ],
                $data['status'] ?? Roadmap::STATUS_ACTIVE,
            );
        }

        $improvementModels = [];
        foreach ($entities['improvements'] as $publicId => $data) {
            $roadmapPublicId = $data['roadmap_public_id'];
            $improvementModels[$publicId] = $this->restoreModel(
                Improvement::withTrashed()->where('project_id', $project->id)->where('public_id', $publicId)->first(),
                new Improvement(),
                [
                    'public_id' => $publicId,
                    'organization_id' => $project->organization_id,
                    'workspace_id' => $project->owning_workspace_id,
                    'project_id' => $project->id,
                    'roadmap_id' => $roadmapPublicId ? ($roadmapModels[$roadmapPublicId]->id ?? null) : null,
                    'proposed_by' => $actor->id,
                    ...$data['attributes'],
                ],
                $data['status'] ?? Improvement::STATUS_PLANNED,
            );
        }

        foreach ($entities['tasks'] as $publicId => $data) {
            $improvementPublicId = $data['improvement_public_id'];
            $this->restoreModel(
                Task::withTrashed()->where('project_id', $project->id)->where('public_id', $publicId)->first(),
                new Task(),
                [
                    'public_id' => $publicId,
                    'organization_id' => $project->organization_id,
                    'workspace_id' => $project->owning_workspace_id,
                    'project_id' => $project->id,
                    'improvement_id' => $improvementModels[$improvementPublicId]->id,
                    'created_by' => $actor->id,
                    ...$data['attributes'],
                ],
                $data['status'] ?? Task::STATUS_TODO,
            );
        }
    }

    private function restoreModel(?Model $existing, Model $fresh, array $attributes, string $restoredStatus): Model
    {
        $model = $existing ?: $fresh;
        $wasMissing = ! $existing || method_exists($model, 'trashed') && $model->trashed();

        if ($existing && method_exists($model, 'trashed') && $model->trashed()) {
            $model->restore();
        }
        if ($existing) {
            unset($attributes['created_by'], $attributes['proposed_by']);
        }
        if ($wasMissing) {
            $attributes['status'] = $restoredStatus;
        }

        $model->fill($attributes)->save();

        return $model;
    }

    private function deleteMissing(Project $project, array $entities): void
    {
        $this->deleteMissingQuery(Task::query()->where('project_id', $project->id), array_keys($entities['tasks']));
        $this->deleteMissingQuery(Improvement::query()->where('project_id', $project->id), array_keys($entities['improvements']));
        $this->deleteMissingQuery(Roadmap::query()->where('project_id', $project->id), array_keys($entities['roadmaps']));
    }

    private function deleteMissingQuery($query, array $publicIds): void
    {
        if ($publicIds === []) {
            $query->delete();
            return;
        }

        $query->whereNotIn('public_id', $publicIds)->delete();
    }

    private function entities(array $snapshot): array
    {
        $entities = ['roadmaps' => [], 'improvements' => [], 'tasks' => []];
        foreach ((array) ($snapshot['roadmaps'] ?? []) as $roadmapIndex => $roadmap) {
            $roadmapId = $this->publicId($roadmap, 'ロードマップ');
            $entities['roadmaps'][$roadmapId] = [
                'attributes' => Arr::only($roadmap, [
                    'title', 'purpose', 'sort_order',
                    'planned_start_date', 'target_date', 'planned_start_day', 'target_day',
                ]) + ['sort_order' => $roadmap['sort_order'] ?? $roadmapIndex + 1],
                'status' => $roadmap['status'] ?? null,
            ];
            $this->appendImprovements($entities, (array) ($roadmap['improvements'] ?? []), $roadmapId);
        }
        $this->appendImprovements($entities, (array) ($snapshot['unclassified_improvements'] ?? []), null);

        return $entities;
    }

    private function appendImprovements(array &$entities, array $improvements, ?string $roadmapId): void
    {
        foreach ($improvements as $improvementIndex => $improvement) {
            $improvementId = $this->publicId($improvement, '取り組み');
            $entities['improvements'][$improvementId] = [
                'roadmap_public_id' => $roadmapId,
                'attributes' => Arr::only($improvement, [
                    'title', 'current_state', 'desired_state', 'problem', 'hypothesis',
                    'action', 'result', 'impact', 'next_action', 'planned_effort_days',
                    'visibility', 'planned_start_date', 'target_date',
                    'planned_start_day', 'target_day', 'roadmap_sort_order',
                ]) + ['roadmap_sort_order' => $improvement['roadmap_sort_order'] ?? $improvementIndex + 1],
                'status' => $improvement['status'] ?? null,
            ];
            foreach ((array) ($improvement['tasks'] ?? []) as $taskIndex => $task) {
                $taskId = $this->publicId($task, 'タスク');
                $entities['tasks'][$taskId] = [
                    'improvement_public_id' => $improvementId,
                    'attributes' => Arr::only($task, [
                        'title', 'description', 'priority', 'planned_start_date', 'due_date',
                        'planned_start_day', 'due_day', 'sort_order',
                    ]) + ['sort_order' => $task['sort_order'] ?? $taskIndex + 1],
                    'status' => $task['status'] ?? null,
                ];
            }
        }
    }

    private function publicId(array $entity, string $label): string
    {
        $publicId = (string) ($entity['public_id'] ?? '');
        if ($publicId === '') {
            throw new RuntimeException($label.'の識別子がないため復元できません。');
        }

        return $publicId;
    }

    private function diffCounts(array $current, array $target): array
    {
        $currentIds = array_keys($current);
        $targetIds = array_keys($target);
        $sharedIds = array_intersect($currentIds, $targetIds);
        $updated = 0;
        foreach ($sharedIds as $publicId) {
            if (Arr::except($current[$publicId], ['status']) !== Arr::except($target[$publicId], ['status'])) {
                $updated++;
            }
        }

        return [
            'restore' => count(array_diff($targetIds, $currentIds)),
            'update' => $updated,
            'remove' => count(array_diff($currentIds, $targetIds)),
            'keep' => count($sharedIds) - $updated,
        ];
    }
}
