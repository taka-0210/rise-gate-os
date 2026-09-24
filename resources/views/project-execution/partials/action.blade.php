<article class="s8-action">
    <div class="s8-toolbar"><strong>{{ $action->title }}</strong><span class="s8-chip">{{ \App\Models\Task::executionStatuses()[$action->status] ?? $action->status }}</span></div>
    <p>{{ $action->done_condition }}</p>
    <div class="s8-meta"><span>担当 {{ $action->assignee?->name }}</span><span>{{ $action->due_date ? '期限 '.$action->due_date->format('Y.m.d') : '期限未設定' }}</span>@if($action->reviewer)<span>Reviewer {{ $action->reviewer->name }}</span>@endif</div>
</article>
