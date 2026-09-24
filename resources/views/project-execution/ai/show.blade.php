@extends('layouts.app', ['title' => 'AI提案Review - '.$project->name])
@section('content')
@include('project-execution._styles')
@php
$statusLabels=['pending'=>'Review待ち','approved'=>'承認済み / Apply前','rejected'=>'修正依頼済み','applied'=>'Apply完了','failed'=>'Apply失敗'];
$entityLabels=['roadmap'=>'Roadmap','improvement'=>'Action Theme','task'=>'Action','project'=>'Project'];
@endphp
<main class="s8-shell s8-ai-shell">
    <header class="s8-hero">
        <div class="s8-kicker">AI PROPOSAL / {{ $proposal->contract_version }}</div>
        <h1>{{ $proposal->title }}</h1>
        @if($proposal->summary)<p class="s8-lead">{{ $proposal->summary }}</p>@endif
        <div class="s8-meta"><span class="s8-chip" data-testid="proposal-status">{{ $statusLabels[$proposal->status] ?? $proposal->status }}</span><span>Project version {{ $proposal->expected_project_version }}</span></div>
        <a href="{{ route('project-execution.ai.index', $project) }}">← AI依頼・提案一覧へ</a>
    </header>
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice danger" data-testid="apply-error">{{ $errors->first() }}</div>@endif

    <section class="s8-card s8-ai-context"><div><div class="s8-kicker">PURPOSE</div><p>{{ $project->purpose }}</p></div><div><div class="s8-kicker">EXPECTED OUTCOME</div><p>{{ $project->expected_outcome }}</p></div></section>

    <section>
        <div class="s8-kicker">REVIEW</div><h2>提案された実行計画</h2>
        <div class="s8-ai-items">
        @foreach($proposal->items as $item)
            @php($after=$item->after ?? [])
            <article class="s8-card s8-ai-item" data-entity-type="{{ $item->entity_type }}">
                <div class="s8-meta"><span class="s8-chip">{{ $entityLabels[$item->entity_type] ?? $item->entity_type }}</span><span>{{ strtoupper($item->operation) }}</span><span>{{ $item->validation_status }}</span></div>
                <h3>{{ $after['title'] ?? ($item->entity_type === 'project' ? $project->name : '名称未設定') }}</h3>
                @if($item->entity_type === 'task' && $item->parent_reference === $project->public_id)<p class="s8-kicker">DIRECT ACTION</p>@endif
                @if(filled($after['purpose'] ?? null))<dl><dt>Purpose</dt><dd>{{ $after['purpose'] }}</dd></dl>@endif
                @if(filled($after['theme_description'] ?? null))<dl><dt>Theme</dt><dd>{{ $after['theme_description'] }}</dd></dl>@endif
                @if(filled($after['description'] ?? null))<dl><dt>Description</dt><dd>{{ $after['description'] }}</dd></dl>@endif
                @if(filled($after['done_condition'] ?? null))<dl><dt>Done Condition</dt><dd>{{ $after['done_condition'] }}</dd></dl>@endif
                @if(array_key_exists('assigned_to',$after))<dl><dt>Assignee</dt><dd>{{ $users[$after['assigned_to']] ?? '未設定' }}</dd></dl>@endif
                @if(array_key_exists('reviewer_user_id',$after))<dl><dt>Reviewer</dt><dd>{{ filled($after['reviewer_user_id']) ? ($users[$after['reviewer_user_id']] ?? '不明') : 'なし' }}</dd></dl>@endif
                @if(array_key_exists('due_date',$after))<dl><dt>期限</dt><dd>{{ $after['due_date'] ?: '未設定' }}</dd></dl>@endif
                @if($item->validation_message)<p class="notice danger">{{ $item->validation_message }}</p>@endif
            </article>
        @endforeach
        </div>
    </section>

    @if($canReview && in_array($proposal->status, ['pending','approved'], true))
    <section class="s8-card"><div class="s8-kicker">REVISION</div><h2>AIへ修正を依頼</h2><form class="s8-form" method="POST" action="{{ route('project-execution.ai.proposals.revision', [$project,$proposal]) }}">@csrf<textarea name="overall_feedback" required placeholder="何を、どのように直してほしいか"></textarea><button class="s8-button secondary" data-testid="request-revision">修正を依頼</button></form></section>
    @endif

    @if($canReview && $proposal->status === 'pending')
    <section class="s8-card"><div class="s8-kicker">APPROVAL</div><h2>内容を承認</h2><p>承認しても、まだProjectへは反映されません。</p><form method="POST" action="{{ route('project-execution.ai.proposals.approve', [$project,$proposal]) }}">@csrf<button class="s8-button" data-testid="approve-proposal">この内容を承認</button></form></section>
    @elseif($canReview && $proposal->status === 'approved')
    <section class="s8-card"><div class="s8-kicker">APPLY</div><h2>Projectへ反映</h2><p>承認済みの内容を現在のProject versionへ原子的に反映します。</p><form method="POST" action="{{ route('project-execution.ai.proposals.apply', [$project,$proposal]) }}">@csrf<button class="s8-button" data-testid="apply-proposal">承認済み提案をApply</button></form></section>
    @endif

    @if($latestAttempt)
    <section class="s8-card" data-testid="apply-result"><div class="s8-kicker">APPLY RESULT</div><h2>{{ strtoupper($latestAttempt->status) }}</h2><div class="s8-meta"><span>反映 {{ $latestAttempt->applied_items_count }}件</span><span>理由 {{ $latestAttempt->error_code ?: 'なし' }}</span><span>{{ $latestAttempt->retryable ? '再試行可' : '再試行不可' }}</span></div>@if($latestAttempt->error_message)<p>{{ $latestAttempt->error_message }}</p>@endif
        @foreach($latestAttempt->itemResults as $result)<p>{{ $entityLabels[$result->item?->entity_type] ?? 'Item' }}：{{ $result->status }} @if($result->error_code)（{{ $result->error_code }}）@endif</p>@endforeach
    </section>
    @endif

    @if($proposal->status === 'applied')<section class="s8-card"><div class="s8-kicker">NEXT</div><h2>反映された実行計画を確認</h2><a class="s8-button" data-testid="return-to-project" href="{{ route('project-execution.show', $project) }}">Scope 8 Projectへ戻る</a></section>@endif
</main>
<style>
.s8-ai-context{display:grid;grid-template-columns:1fr 1fr;gap:32px}.s8-ai-items{display:grid;gap:16px}.s8-ai-item dl{display:grid;grid-template-columns:140px 1fr;gap:10px;margin:14px 0}.s8-ai-item dt{color:#60717a}.s8-ai-item dd{margin:0}@media(max-width:600px){.s8-ai-context{grid-template-columns:1fr}.s8-ai-item dl{grid-template-columns:1fr;gap:4px}.s8-ai-item{overflow-wrap:anywhere}}
</style>
@endsection