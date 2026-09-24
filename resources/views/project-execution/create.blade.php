@extends('layouts.app', ['title' => 'Projectを作成 - '.$company->name])
@section('content')
@include('project-execution._styles')
<main class="s8-shell"><header class="s8-hero"><div class="s8-kicker">NEW PROJECT</div><h1>目的から始める。</h1><p class="s8-lead">最小Projectは、名前・目的・期待成果・Ownerで始まります。</p></header>
<form class="s8-card s8-form" method="POST" action="{{ route('project-execution.store') }}">@csrf
    <div><label for="workspace_id">Workspace</label><select id="workspace_id" name="workspace_id" required>@foreach($workspaces as $workspace)<option value="{{ $workspace->id }}">{{ $workspace->name }}</option>@endforeach</select></div>
    <div><label for="name">Project名</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="150"></div>
    <div><label for="purpose">Purpose</label><textarea id="purpose" name="purpose" rows="4" required>{{ old('purpose') }}</textarea></div>
    <div><label for="expected_outcome">Expected Outcome</label><textarea id="expected_outcome" name="expected_outcome" rows="4" required>{{ old('expected_outcome') }}</textarea></div>
    <div class="s8-form-row"><div><label for="start_date">開始日（任意）</label><input id="start_date" type="date" name="start_date" value="{{ old('start_date') }}"></div><div><label for="due_date">期限（任意）</label><input id="due_date" type="date" name="due_date" value="{{ old('due_date') }}"></div></div>
    <button class="s8-button" type="submit">Projectを作成</button>
</form></main>
@endsection
