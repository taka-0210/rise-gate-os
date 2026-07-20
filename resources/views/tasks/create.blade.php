@extends('layouts.app', ['title' => 'タスクを登録'])

@section('content')
<section class="panel stack">
    <div><div class="meta">{{ $project->name }}</div><h1>タスクを登録</h1><p>取り組みを前へ進める具体的な作業を登録します。</p></div>
    <form class="stack" method="POST" action="{{ route('projects.tasks.store',$project) }}">
        @csrf
        <div class="field"><label for="improvement_id">所属する取り組み</label><select id="improvement_id" name="improvement_id" required><option value="">選択してください</option>@foreach($initiatives as $initiative)<option value="{{ $initiative->id }}" @selected((string)old('improvement_id',$selectedImprovementId)===(string)$initiative->id)>{{ $initiative->roadmap?->title }} / {{ $initiative->title }}</option>@endforeach</select>@error('improvement_id')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="title">タスク名</label><input id="title" name="title" value="{{ old('title') }}" required autofocus>@error('title')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="description">説明</label><textarea id="description" name="description" rows="4">{{ old('description') }}</textarea></div>
        <div class="grid"><div class="field"><label for="status">進行状況</label><select id="status" name="status">@foreach($statuses as $value=>$label)<option value="{{ $value }}" @selected(old('status','todo')===$value)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="priority">優先度</label><select id="priority" name="priority">@foreach($priorities as $value=>$label)<option value="{{ $value }}" @selected(old('priority','normal')===$value)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="assigned_to">担当者</label><select id="assigned_to" name="assigned_to"><option value="">未設定</option>@foreach($assignableUsers as $user)<option value="{{ $user->id }}" @selected((string)old('assigned_to')===(string)$user->id)>{{ $user->name }}</option>@endforeach</select></div></div>
        <div class="grid two">@if(!$project->start_date && $project->duration_days)<div class="field"><label for="planned_start_day">開始（着手後・日目）</label><input id="planned_start_day" name="planned_start_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('planned_start_day') }}"></div><div class="field"><label for="due_day">完了（着手後・日目）</label><input id="due_day" name="due_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('due_day') }}"></div>@else<div class="field"><label for="planned_start_date">開始予定日</label><input id="planned_start_date" name="planned_start_date" type="date" value="{{ old('planned_start_date') }}"></div><div class="field"><label for="due_date">完了予定日</label><input id="due_date" name="due_date" type="date" value="{{ old('due_date') }}"></div>@endif</div>
        <div class="actions"><button type="submit">登録する</button><a class="button secondary" href="{{ route('projects.show',$project) }}">キャンセル</a></div>
    </form>
</section>
@endsection
