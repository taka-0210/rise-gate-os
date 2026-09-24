@extends('layouts.app', ['title' => $project->name.' - 実行管理'])
@section('content')
@include('project-execution._styles')
<main class="s8-shell">
    <header class="s8-hero"><div class="s8-toolbar"><div><div class="s8-kicker">PROJECT / MANAGE</div><h1>{{ $project->name }}</h1></div><div class="actions"><a class="s8-button" href="{{ route('project-execution.ai.index', $project) }}">AIと実行計画をつくる</a><a href="{{ route('project-execution.show', $project) }}">Projectへ戻る</a></div></div><p>Owner・Member・Assignee・Reviewerの責任を分けた実行画面です。</p></header>
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice danger">{{ $errors->first() }}</div>@endif

    @if($project->owner_user_id === auth()->id())
    <section class="s8-card"><div class="s8-kicker">PROJECT DEFINITION</div><h2>目的と期待成果</h2>
        <form class="s8-form" method="POST" action="{{ route('project-execution.update', $project) }}">@csrf @method('PUT')<input type="hidden" name="project_version" value="{{ $project->plan_version }}">
            <div><label>Project名</label><input name="name" value="{{ $project->name }}" required></div><div><label>Purpose</label><textarea name="purpose" required>{{ $project->purpose }}</textarea></div><div><label>Expected Outcome</label><textarea name="expected_outcome" required>{{ $project->expected_outcome }}</textarea></div>
            <div class="s8-form-row"><div><label>開始日</label><input type="date" name="start_date" value="{{ $project->start_date?->format('Y-m-d') }}"></div><div><label>期限</label><input type="date" name="due_date" value="{{ $project->due_date?->format('Y-m-d') }}"></div></div><button class="s8-button">更新</button>
        </form>
    </section>
    <section class="s8-card"><div class="s8-kicker">ROADMAP</div><h2>Roadmapを追加</h2><form class="s8-form" method="POST" action="{{ route('project-execution.roadmaps.store', $project) }}">@csrf<input type="hidden" name="project_version" value="{{ $project->plan_version }}"><input name="title" placeholder="Roadmap名" required><textarea name="purpose" placeholder="目的（任意）"></textarea><button class="s8-button">追加</button></form></section>
    @include('project-execution.partials.management-roles')
    @endif

    @foreach($project->roadmaps as $roadmap)
        <section class="s8-card"><div class="s8-kicker">ROADMAP</div><h2>{{ $roadmap->title }}</h2>
            @if($project->owner_user_id === auth()->id())<form class="s8-form" method="POST" action="{{ route('project-execution.themes.store', [$project,$roadmap]) }}">@csrf<input type="hidden" name="project_version" value="{{ $project->plan_version }}"><input name="title" placeholder="Action Theme名" required><textarea name="description" placeholder="説明（任意）"></textarea><button class="s8-button">Themeを追加</button></form>@endif
            @foreach($roadmap->improvements as $theme)<div style="margin-top:24px"><h3>{{ $theme->title }}</h3>@foreach($theme->tasks as $action)@include('project-execution.partials.manage-action', compact('action','project'))@endforeach</div>@endforeach
        </section>
    @endforeach

    @if($project->owner_user_id === auth()->id() || $project->members->firstWhere('user_id', auth()->id())?->hasExecutionRole('member'))
    <section class="s8-card"><div class="s8-kicker">NEW ACTION</div><h2>Actionを追加</h2><form class="s8-form" method="POST" action="{{ route('project-execution.actions.store', $project) }}">@csrf<input type="hidden" name="project_version" value="{{ $project->plan_version }}">
        <div><label>配置</label><select name="improvement_id"><option value="">Project直下</option>@foreach($project->roadmaps as $roadmap)@foreach($roadmap->improvements as $theme)<option value="{{ $theme->id }}">{{ $roadmap->title }} / {{ $theme->title }}</option>@endforeach @endforeach</select></div>
        <div><label>Action名</label><input name="title" required></div><div><label>Done Condition</label><textarea name="done_condition" required></textarea></div>
        <div class="s8-form-row"><div><label>主担当</label><select name="assigned_to" required>@foreach($eligibleUsers as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div><div><label>Reviewer（任意）</label><select name="reviewer_user_id"><option value="">なし</option>@foreach($eligibleUsers as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div></div>
        <div><label>期限（任意）</label><input type="date" name="due_date"></div><button class="s8-button">Actionを追加</button>
    </form></section>
    @endif

    @if($project->tasks->isNotEmpty())<section class="s8-card"><div class="s8-kicker">DIRECT ACTION</div><h2>Project直下</h2>@foreach($project->tasks->whereNull('improvement_id') as $action)@include('project-execution.partials.manage-action', compact('action','project'))@endforeach</section>@endif
    @if($project->owner_user_id === auth()->id())
    <section class="s8-card"><div class="s8-kicker">PROJECT COMPLETION</div><h2>Close with current child states</h2><p>Incomplete child items remain unchanged. Confirm the current Project version before closing.</p>
        <form method="POST" action="{{ route('project-execution.complete', $project) }}">@csrf<input type="hidden" name="project_version" value="{{ $project->plan_version }}"><input type="hidden" name="confirmed_project_version" value="{{ $project->plan_version }}"><input type="hidden" name="type" value="project"><button class="s8-button">Request or complete Project</button></form>
    </section>
    @endif
    @if($project->reviewer_user_id === auth()->id() && $project->review_status === 'pending')
    <section class="s8-card"><div class="s8-kicker">PROJECT REVIEW</div><h2>Completion review</h2>
        <form method="POST" action="{{ route('project-execution.review', $project) }}" style="display:inline-block">@csrf<input type="hidden" name="project_version" value="{{ $project->plan_version }}"><input type="hidden" name="decision" value="confirm"><button class="s8-button">Confirm</button></form>
        <form method="POST" action="{{ route('project-execution.review', $project) }}" class="s8-form" style="margin-top:12px">@csrf<input type="hidden" name="project_version" value="{{ $project->plan_version }}"><input type="hidden" name="decision" value="reject"><input name="reason" required placeholder="Reason"><button class="s8-button">Reject</button></form>
    </section>
    @endif
</main>
@endsection
