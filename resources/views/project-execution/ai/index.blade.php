@extends('layouts.app', ['title' => 'AIと実行計画をつくる - '.$project->name])
@section('content')
@include('project-execution._styles')
<main class="s8-shell s8-ai-shell">
    <header class="s8-hero">
        <div class="s8-kicker">AI / PROJECT ACTION</div>
        <h1>AIと実行計画をつくる</h1>
        <p class="s8-lead">Projectの目的と期待成果を起点に、Roadmap・Action Theme・Actionの構成を提案します。人の承認とApplyまでは本データへ反映されません。</p>
        <a href="{{ route('project-execution.show', $project) }}">← {{ $project->name }}へ戻る</a>
    </header>
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice danger">{{ $errors->first() }}</div>@endif

    <section class="s8-card s8-ai-context" data-testid="ai-context">
        <div><div class="s8-kicker">PURPOSE</div><p>{{ $project->purpose }}</p></div>
        <div><div class="s8-kicker">EXPECTED OUTCOME</div><p>{{ $project->expected_outcome }}</p></div>
    </section>

    <section class="s8-card">
        <div class="s8-kicker">REQUEST</div><h2>AIへ実行計画を依頼</h2>
        <form class="s8-form" method="POST" action="{{ route('project-execution.ai.requests.store', $project) }}">
            @csrf
            <div><label for="ai-title">依頼名</label><input id="ai-title" name="title" value="{{ old('title', '実行計画の提案') }}" required></div>
            <div><label for="ai-instructions">追加の条件</label><textarea id="ai-instructions" name="instructions" required placeholder="優先順位、期限、体制など、AIに考慮してほしい条件">{{ old('instructions') }}</textarea></div>
            <button class="s8-button" data-testid="request-ai-plan">AIへ提案を依頼</button>
        </form>
    </section>

    <section>
        <div class="s8-kicker">PROPOSALS</div><h2>Reviewする提案</h2>
        <div class="s8-ai-list">
        @forelse($proposals as $proposal)
            <a class="s8-card s8-ai-row" data-testid="proposal-row" href="{{ route('project-execution.ai.proposals.show', [$project, $proposal]) }}">
                <span><strong>{{ $proposal->title }}</strong><small>{{ $proposal->items_count }}項目 / {{ $proposal->created_at->format('Y.m.d H:i') }}</small></span>
                <span class="s8-chip">{{ strtoupper($proposal->status) }}</span>
            </a>
        @empty
            <div class="s8-card"><p>届いた提案はまだありません。依頼後、AIが提案を作成するとここに表示されます。</p></div>
        @endforelse
        </div>
    </section>

    <section class="s8-card">
        <div class="s8-kicker">REQUEST STATUS</div><h2>AI依頼</h2>
        @forelse($requests as $aiRequest)
            <div class="s8-ai-request" data-ai-request="{{ $aiRequest->public_id }}"><strong>{{ $aiRequest->title }}</strong><span>{{ strtoupper($aiRequest->status) }}</span></div>
        @empty<p>依頼はありません。</p>@endforelse
    </section>
</main>
<style>
.s8-ai-context{display:grid;grid-template-columns:1fr 1fr;gap:32px}.s8-ai-list{display:grid;gap:12px}.s8-ai-row{display:flex;justify-content:space-between;align-items:center;text-decoration:none;color:inherit}.s8-ai-row small{display:block;margin-top:8px;color:#60717a}.s8-ai-request{display:flex;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid #d9e3e7}.s8-ai-request:last-child{border:0}@media(max-width:600px){.s8-ai-context{grid-template-columns:1fr}.s8-ai-row{align-items:flex-start;gap:16px}}
</style>
@endsection