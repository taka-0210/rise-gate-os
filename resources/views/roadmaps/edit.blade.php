@extends('layouts.app', ['title' => 'Roadmapを編集 - '.$roadmap->title])

@section('content')
    <section class="card stack">
        <div>
            <div class="meta">{{ $project->name }} / Roadmap</div>
            <h1>Roadmapを編集</h1>
            <p>実現までの道筋と、その時間的な目安を設定します。</p>
        </div>
        <form class="stack" method="POST" action="{{ route('projects.roadmaps.update', [$project, $roadmap]) }}">
            @csrf
            @method('PUT')
            <div class="field"><label for="title">Roadmap名</label><input id="title" name="title" value="{{ old('title', $roadmap->title) }}" required autofocus>@error('title') <div class="error">{{ $message }}</div> @enderror</div>
            <div class="field"><label for="purpose">目指す状態</label><textarea id="purpose" name="purpose" rows="4">{{ old('purpose', $roadmap->purpose) }}</textarea>@error('purpose') <div class="error">{{ $message }}</div> @enderror</div>
            <div class="grid two">
                @if(!$project->start_date && $project->duration_days)
                    <div class="field"><label for="planned_start_day">開始（着手後・日目）</label><input id="planned_start_day" name="planned_start_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('planned_start_day', $roadmap->planned_start_day) }}">@error('planned_start_day') <div class="error">{{ $message }}</div> @enderror</div>
                    <div class="field"><label for="target_day">完了（着手後・日目）</label><input id="target_day" name="target_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('target_day', $roadmap->target_day) }}">@error('target_day') <div class="error">{{ $message }}</div> @enderror</div>
                @else
                    <div class="field"><label for="planned_start_date">開始予定日</label><input id="planned_start_date" name="planned_start_date" type="date" value="{{ old('planned_start_date', $roadmap->planned_start_date?->format('Y-m-d')) }}">@error('planned_start_date') <div class="error">{{ $message }}</div> @enderror</div>
                    <div class="field"><label for="target_date">到達予定日</label><input id="target_date" name="target_date" type="date" value="{{ old('target_date', $roadmap->target_date?->format('Y-m-d')) }}">@error('target_date') <div class="error">{{ $message }}</div> @enderror</div>
                @endif
            </div>
            <div class="grid two">
                <div class="field"><label for="reached_at">実際の到達日</label><input id="reached_at" name="reached_at" type="date" value="{{ old('reached_at', $roadmap->reached_at?->format('Y-m-d')) }}">@error('reached_at') <div class="error">{{ $message }}</div> @enderror</div>
                <div class="field"><label for="status">進行状況</label><select id="status" name="status" required>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(old('status', $roadmap->status) === $value)>{{ $label }}</option>@endforeach</select>@error('status') <div class="error">{{ $message }}</div> @enderror</div>
            </div>
            <div class="actions"><button type="submit">Roadmapを更新</button><a class="button secondary" href="{{ route('projects.show', $project) }}">戻る</a></div>
        </form>
    </section>
@endsection
