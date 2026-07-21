@extends('layouts.app', ['title' => 'ロードマップを登録'])

@section('content')
<section class="panel stack">
    <div><div class="meta">{{ $project->name }}</div><h1>ロードマップを登録</h1><p>目指す到達点と、その期間を設定します。</p></div>
    <form class="stack" method="POST" action="{{ route('projects.roadmaps.store', $project) }}">
        @csrf
        <div class="field"><label for="title">ロードマップ名</label><input id="title" name="title" value="{{ old('title') }}" required autofocus>@error('title')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="purpose">目的・目指す状態</label><textarea id="purpose" name="purpose" rows="4">{{ old('purpose') }}</textarea>@error('purpose')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="grid two">
            @if(!$project->start_date && $project->duration_days)
                <div class="field"><label for="planned_start_day">開始（着手後・日目）</label><input id="planned_start_day" name="planned_start_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('planned_start_day') }}">@error('planned_start_day')<div class="error">{{ $message }}</div>@enderror</div>
                <div class="field"><label for="target_day">完了（着手後・日目）</label><input id="target_day" name="target_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('target_day') }}">@error('target_day')<div class="error">{{ $message }}</div>@enderror</div>
            @else
                <div class="field"><label for="planned_start_date">開始予定日</label><input id="planned_start_date" name="planned_start_date" type="date" value="{{ old('planned_start_date') }}">@error('planned_start_date')<div class="error">{{ $message }}</div>@enderror</div>
                <div class="field"><label for="target_date">完了予定日</label><input id="target_date" name="target_date" type="date" value="{{ old('target_date') }}">@error('target_date')<div class="error">{{ $message }}</div>@enderror</div>
            @endif
        </div>
        <div class="meta">進捗は、配下の取り組みの予定工数とタスクの完了状況から自動計算されます。</div>
        <div class="actions"><button type="submit">登録する</button><a class="button secondary" href="{{ route('projects.show',$project) }}">キャンセル</a></div>
    </form>
</section>
@endsection
