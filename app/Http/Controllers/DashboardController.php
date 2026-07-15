<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const WEEKLY_IMPROVEMENT_GOAL = 5;
    private const STALLED_DAYS = 14;

    public function __invoke(Request $request): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        $currentWorkspaceRole = $request->attributes->get('currentWorkspaceRole');
        $user = $request->user();
        $now = now();

        $memberships = ProjectMember::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $currentWorkspace->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->get(['project_id', 'project_role']);

        $visibleProjectIds = $memberships->pluck('project_id')->all();
        $clientProjectIds = $memberships
            ->where('project_role', ProjectMember::ROLE_CLIENT)
            ->pluck('project_id')
            ->all();
        $internalProjectIds = $memberships
            ->reject(fn (ProjectMember $member): bool => $member->project_role === ProjectMember::ROLE_CLIENT)
            ->pluck('project_id')
            ->all();

        $completedStatuses = [
            Improvement::STATUS_IMPLEMENTED,
            Improvement::STATUS_MEASURED,
            Improvement::STATUS_CLOSED,
        ];
        $openStatuses = [
            Improvement::STATUS_PROPOSED,
            Improvement::STATUS_PLANNED,
            Improvement::STATUS_IN_PROGRESS,
        ];

        $workspaceImprovementQuery = Improvement::query()
            ->where('workspace_id', $currentWorkspace->id);
        $visibleImprovementQuery = $this->visibleImprovementQuery($internalProjectIds, $clientProjectIds);

        $todayImprovementCount = (clone $visibleImprovementQuery)
            ->whereDate('created_at', $now->toDateString())
            ->count();
        $todayCompletedCount = (clone $visibleImprovementQuery)
            ->whereIn('status', $completedStatuses)
            ->whereDate('updated_at', $now->toDateString())
            ->count();
        $todayProjectUpdateCount = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->whereDate('updated_at', $now->toDateString())
            ->count();

        $weekStart = $now->copy()->startOfWeek();
        $previousWeekStart = $weekStart->copy()->subWeek();
        $previousWeekEnd = $weekStart->copy()->subSecond();

        $weekImprovementCount = (clone $visibleImprovementQuery)
            ->where('created_at', '>=', $weekStart)
            ->count();
        $weekCompletedCount = (clone $visibleImprovementQuery)
            ->whereIn('status', $completedStatuses)
            ->where('updated_at', '>=', $weekStart)
            ->count();
        $weekProjectUpdateCount = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->where('updated_at', '>=', $weekStart)
            ->count();
        $weekKnowledgeCount = (clone $visibleImprovementQuery)
            ->where('updated_at', '>=', $weekStart)
            ->where(function (Builder $query): void {
                $query->whereNotNull('result')
                    ->orWhereNotNull('impact')
                    ->orWhereNotNull('next_action');
            })
            ->count();
        $previousWeekImprovementCount = (clone $visibleImprovementQuery)
            ->whereBetween('created_at', [$previousWeekStart, $previousWeekEnd])
            ->count();
        $resultWaitingCount = (clone $visibleImprovementQuery)
            ->whereIn('status', $completedStatuses)
            ->where(function (Builder $query): void {
                $query->whereNull('result')
                    ->orWhere('result', '')
                    ->orWhereNull('impact')
                    ->orWhere('impact', '');
            })
            ->count();

        $weekEvolutionCount = $weekImprovementCount + $weekCompletedCount + $weekProjectUpdateCount + $weekKnowledgeCount;

        $stats = [
            'clients' => Client::query()->where('workspace_id', $currentWorkspace->id)->count(),
            'projects' => Project::query()->where('owning_workspace_id', $currentWorkspace->id)->count(),
            'improvements' => (clone $workspaceImprovementQuery)->count(),
            'open_improvements' => (clone $workspaceImprovementQuery)->whereIn('status', $openStatuses)->count(),
            'completed_improvements' => (clone $workspaceImprovementQuery)->whereIn('status', $completedStatuses)->count(),
            'recent_improvements' => (clone $workspaceImprovementQuery)->where('created_at', '>=', $weekStart)->count(),
        ];

        $nextToGrow = (clone $visibleImprovementQuery)
            ->whereIn('status', $openStatuses)
            ->with(['project.client', 'assignee'])
            ->orderByRaw('CASE WHEN assigned_to = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByRaw('CASE WHEN result IS NULL OR result = ? THEN 0 ELSE 1 END', [''])
            ->orderBy('updated_at')
            ->limit(5)
            ->get();

        $stalledImprovements = (clone $visibleImprovementQuery)
            ->whereIn('status', $openStatuses)
            ->where('updated_at', '<=', $now->copy()->subDays(self::STALLED_DAYS))
            ->with(['project.client', 'assignee'])
            ->oldest('updated_at')
            ->limit(5)
            ->get();

        $assignedImprovements = (clone $visibleImprovementQuery)
            ->where('assigned_to', $user->id)
            ->whereIn('status', $openStatuses)
            ->with(['project.client'])
            ->latest('updated_at')
            ->limit(5)
            ->get();

        $applyVisibleImprovementScope = function (Builder $query) use ($internalProjectIds, $clientProjectIds): void {
            $query->where(function (Builder $query) use ($internalProjectIds, $clientProjectIds): void {
                if ($internalProjectIds !== []) {
                    $query->whereIn('project_id', $internalProjectIds);
                }

                if ($clientProjectIds !== []) {
                    $query->orWhere(function (Builder $query) use ($clientProjectIds): void {
                        $query->whereIn('project_id', $clientProjectIds)
                            ->where('visibility', Improvement::VISIBILITY_CLIENT);
                    });
                }
            });
        };

        $recentProjects = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->with(['client'])
            ->withCount([
                'improvements' => fn (Builder $query) => $applyVisibleImprovementScope($query),
                'improvements as open_improvements_count' => function (Builder $query) use ($openStatuses, $applyVisibleImprovementScope): void {
                    $applyVisibleImprovementScope($query);
                    $query->whereIn('status', $openStatuses);
                },
                'improvements as completed_improvements_count' => function (Builder $query) use ($completedStatuses, $applyVisibleImprovementScope): void {
                    $applyVisibleImprovementScope($query);
                    $query->whereIn('status', $completedStatuses);
                },
            ])
            ->latest('updated_at')
            ->limit(5)
            ->get();

        $this->attachProjectMovement($recentProjects, $completedStatuses, $internalProjectIds, $clientProjectIds);

        return view('dashboard.index', [
            'currentWorkspace' => $currentWorkspace,
            'currentWorkspaceRole' => $currentWorkspaceRole,
            'stats' => $stats,
            'todayEvolutionCount' => $todayImprovementCount + $todayCompletedCount + $todayProjectUpdateCount,
            'todayImprovementCount' => $todayImprovementCount,
            'todayCompletedCount' => $todayCompletedCount,
            'todayProjectUpdateCount' => $todayProjectUpdateCount,
            'weekEvolutionCount' => $weekEvolutionCount,
            'weekImprovementCount' => $weekImprovementCount,
            'weekCompletedCount' => $weekCompletedCount,
            'weekProjectUpdateCount' => $weekProjectUpdateCount,
            'weekKnowledgeCount' => $weekKnowledgeCount,
            'previousWeekImprovementCount' => $previousWeekImprovementCount,
            'resultWaitingCount' => $resultWaitingCount,
            'weeklyGoal' => self::WEEKLY_IMPROVEMENT_GOAL,
            'weeklyGoalRemaining' => max(self::WEEKLY_IMPROVEMENT_GOAL - $weekImprovementCount, 0),
            'nextToGrow' => $nextToGrow,
            'stalledImprovements' => $stalledImprovements,
            'assignedImprovements' => $assignedImprovements,
            'recentProjects' => $recentProjects,
            'statuses' => Improvement::statuses(),
            'stalledDays' => self::STALLED_DAYS,
        ]);
    }

    private function visibleImprovementQuery(array $internalProjectIds, array $clientProjectIds): Builder
    {
        if ($internalProjectIds === [] && $clientProjectIds === []) {
            return Improvement::query()->whereRaw('1 = 0');
        }

        return Improvement::query()
            ->where(function (Builder $query) use ($internalProjectIds, $clientProjectIds): void {
                if ($internalProjectIds !== []) {
                    $query->whereIn('project_id', $internalProjectIds);
                }

                if ($clientProjectIds !== []) {
                    $query->orWhere(function (Builder $query) use ($clientProjectIds): void {
                        $query->whereIn('project_id', $clientProjectIds)
                            ->where('visibility', Improvement::VISIBILITY_CLIENT);
                    });
                }
            });
    }

    private function attachProjectMovement(Collection $projects, array $completedStatuses, array $internalProjectIds, array $clientProjectIds): void
    {
        if ($projects->isEmpty()) {
            return;
        }

        $projectIds = $projects->pluck('id')->all();

        $latestImprovements = $this->visibleImprovementQuery($internalProjectIds, $clientProjectIds)
            ->whereIn('project_id', $projectIds)
            ->latest('updated_at')
            ->get()
            ->groupBy('project_id')
            ->map(fn (Collection $items): ?Improvement => $items->first());

        $recentCompleted = $this->visibleImprovementQuery($internalProjectIds, $clientProjectIds)
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', $completedStatuses)
            ->latest('updated_at')
            ->get()
            ->groupBy('project_id')
            ->map(fn (Collection $items): ?Improvement => $items->first());

        $projects->each(function (Project $project) use ($latestImprovements, $recentCompleted): void {
            $project->setRelation('latestImprovement', $latestImprovements->get($project->id));
            $project->setRelation('recentCompletedImprovement', $recentCompleted->get($project->id));
        });
    }
}