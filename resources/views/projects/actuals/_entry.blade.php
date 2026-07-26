<article class="actual-entry">
    <div>
        <strong>{{ $actual->title }}</strong>
        <p class="meta">
            実績：{{ $actual->actual_started_at?->format('Y/m/d') ?? '未入力' }}
            〜 {{ $actual->actual_completed_at?->format('Y/m/d') ?? '未入力' }}
            ／ {{ $formatHours($actual->effort_minutes) }}
            ／ {{ $actual->status === \App\Models\ProjectActual::STATUS_CONFIRMED ? '確定' : '記録' }}
        </p>
        @if($actual->description)<p>{!! nl2br(e($actual->description)) !!}</p>@endif
        @if($actual->result)<p><strong>成果：</strong>{!! nl2br(e($actual->result)) !!}</p>@endif
        <p class="meta">{{ $actual->recorder?->name ?? '記録者不明' }}・{{ $actual->recorded_at?->timezone(config('app.display_timezone'))->format('Y/m/d H:i') }}</p>
    </div>
    @if($canEdit)
        <form method="POST" action="{{ route('projects.actuals.destroy', [$project, $actual]) }}" onsubmit="return confirm('この実績を削除しますか？予定は変更されません。')">
            @csrf
            @method('DELETE')
            <button class="secondary" type="submit">削除</button>
        </form>
    @endif
</article>
