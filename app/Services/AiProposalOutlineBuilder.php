<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;

class AiProposalOutlineBuilder
{
    public function build(Project $project, AiProposal $proposal): array
    {
        $project->loadMissing(['roadmaps.improvements.tasks']);
        $proposal->loadMissing('items');
        $outline = [];

        foreach ($proposal->items->where('entity_type', 'roadmap') as $item) {
            $key = $this->itemKey($item);
            $roadmap = $item->target_public_id
                ? $project->roadmaps->firstWhere('public_id', $item->target_public_id)
                : null;
            $outline[$key] = $this->roadmapNode(
                $item->attributes['title'] ?? $roadmap?->title ?? '名称未設定のロードマップ',
                $item->operation,
                $item->id
            );
        }

        foreach ($proposal->items->where('entity_type', 'improvement') as $item) {
            $improvement = $item->target_public_id
                ? $project->improvements->firstWhere('public_id', $item->target_public_id)
                : null;
            $roadmapKey = $item->parent_reference
                ? 'reference:'.$item->parent_reference
                : ($item->attributes['roadmap_public_id'] ?? $improvement?->roadmap?->public_id ?? 'unassigned-roadmap');
            $roadmap = $improvement?->roadmap
                ?? $project->roadmaps->firstWhere('public_id', $roadmapKey);
            $outline[$roadmapKey] ??= $this->roadmapNode($roadmap?->title ?? 'ロードマップ未指定', 'context');
            $outline[$roadmapKey]['improvements'][$this->itemKey($item)] = $this->improvementNode(
                $item->attributes['title'] ?? $improvement?->title ?? '名称未設定の取り組み',
                $item->operation,
                $item->id
            );
        }

        foreach ($proposal->items->where('entity_type', 'task') as $item) {
            $task = $item->target_public_id
                ? $project->tasks->firstWhere('public_id', $item->target_public_id)
                : null;
            $improvementKey = $item->parent_reference
                ? 'reference:'.$item->parent_reference
                : ($item->attributes['improvement_public_id'] ?? $task?->improvement?->public_id ?? 'unassigned-improvement');
            $proposalImprovement = $proposal->items
                ->where('entity_type', 'improvement')
                ->first(fn (AiProposalItem $candidate) => $this->itemKey($candidate) === $improvementKey);
            $improvement = $task?->improvement
                ?? $project->improvements->firstWhere('public_id', $improvementKey);
            $roadmapKey = $proposalImprovement?->parent_reference
                ? 'reference:'.$proposalImprovement->parent_reference
                : ($proposalImprovement?->attributes['roadmap_public_id'] ?? $improvement?->roadmap?->public_id ?? 'unassigned-roadmap');
            $roadmap = $improvement?->roadmap
                ?? $project->roadmaps->firstWhere('public_id', $roadmapKey);

            $outline[$roadmapKey] ??= $this->roadmapNode($roadmap?->title ?? 'ロードマップ未指定', 'context');
            $outline[$roadmapKey]['improvements'][$improvementKey] ??= $this->improvementNode(
                $proposalImprovement?->attributes['title'] ?? $improvement?->title ?? '取り組み未指定',
                $proposalImprovement?->operation ?? 'context'
            );
            $outline[$roadmapKey]['improvements'][$improvementKey]['tasks'][] = [
                'title' => $item->attributes['title'] ?? $task?->title ?? '名称未設定のタスク',
                'operation' => $item->operation,
                'item_id' => $item->id,
            ];
        }

        return array_values(array_map(function (array $roadmap): array {
            $roadmap['improvements'] = array_values($roadmap['improvements']);
            return $roadmap;
        }, $outline));
    }

    private function itemKey(AiProposalItem $item): string
    {
        return $item->reference_key ? 'reference:'.$item->reference_key : ($item->target_public_id ?? 'item:'.$item->id);
    }

    private function roadmapNode(string $title, string $operation, ?int $itemId = null): array
    {
        return ['title' => $title, 'operation' => $operation, 'item_id' => $itemId, 'improvements' => []];
    }

    private function improvementNode(string $title, string $operation, ?int $itemId = null): array
    {
        return ['title' => $title, 'operation' => $operation, 'item_id' => $itemId, 'tasks' => []];
    }
}
