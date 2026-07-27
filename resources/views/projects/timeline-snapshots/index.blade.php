@extends('layouts.app', ['title' => 'タイムライン保存履歴 - '.$project->name])

@section('content')
<style>
    .timeline-history-page{position:relative;left:50%;width:min(1560px,calc(100vw - 40px));transform:translateX(-50%)}
    .timeline-history-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}
    .timeline-save-form{display:grid;grid-template-columns:minmax(260px,.7fr) minmax(320px,1.3fr) auto;gap:12px;align-items:end}
    .timeline-save-form .field{margin:0}
    .timeline-history-list{display:grid;gap:12px}
    .timeline-history-card{display:grid;grid-template-columns:130px minmax(0,1fr) auto;gap:18px;align-items:center;padding:18px;border:1px solid var(--line);border-radius:10px;background:#fff}
    .timeline-history-card time{font-weight:900;color:var(--accent-dark)}
    .timeline-history-card h2{margin:0 0 5px}
    .timeline-history-type{display:inline-flex;width:max-content;margin-bottom:6px;padding:3px 7px;border-radius:999px;background:#e8f4f2;color:var(--accent-dark);font-size:11px;font-weight:900}
    .timeline-history-type.is-proposal{background:#edf2fb;color:#355f9e}
    @media(max-width:760px){
        .timeline-history-page{width:calc(100vw - 28px)}
        .timeline-history-head,.timeline-save-form,.timeline-history-card{grid-template-columns:1fr;display:grid}
    }
</style>

<section class="stack timeline-history-page">
    <div class="timeline-history-head">
        <div>
            <div class="eyebrow">PROJECT / TIMELINE HISTORY</div>
            <h1>タイムライン保存履歴</h1>
            <p>{{ $project->name }}の、提出時・合意時・節目ごとの状態を保存しています。</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('projects.show', ['project' => $project, 'view' => 'time']) }}">現在のタイムライン</a>
            <a class="button secondary" href="{{ route('projects.index') }}">PROJECT一覧</a>
        </div>
    </div>

    @if(session('status'))<div class="panel" style="border-color:#b7d8c2;background:#f3fbf6;">{{ session('status') }}</div>@endif

    @if($canSave)
        <form class="panel timeline-save-form" method="POST" action="{{ route('projects.timeline-snapshots.store', $project) }}">
            @csrf
            <div class="field">
                <label for="timeline_snapshot_title">保存名</label>
                <input id="timeline_snapshot_title" name="title" value="{{ old('title', now()->format('Y年n月j日 H:i').' 時点') }}" required maxlength="255">
                @error('title')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="timeline_snapshot_note">メモ（任意）</label>
                <input id="timeline_snapshot_note" name="note" value="{{ old('note') }}" maxlength="3000" placeholder="例：初回見積提出時、仕様変更後、最終承認時">
                @error('note')<div class="error">{{ $message }}</div>@enderror
            </div>
            <button type="submit">現在を保存</button>
        </form>
    @endif

    <div class="timeline-history-list">
        @forelse($versions as $version)
            <article class="timeline-history-card">
                <time datetime="{{ $version->created_at?->toIso8601String() }}">
                    {{ $version->created_at?->format('Y.m.d') }}<br>
                    <span class="meta">{{ $version->created_at?->format('H:i') }}</span>
                </time>
                <div>
                    <span class="timeline-history-type {{ in_array($version->version_type, [\App\Models\ProjectPlanVersion::TYPE_PROPOSAL, \App\Models\ProjectPlanVersion::TYPE_PROPOSAL_BEFORE], true) ? 'is-proposal' : '' }}">
                        {{ match ($version->version_type) {
                            \App\Models\ProjectPlanVersion::TYPE_PROPOSAL_BEFORE => 'AI提案反映直前',
                            \App\Models\ProjectPlanVersion::TYPE_PROPOSAL => 'AI提案反映直前',
                            default => '手動保存',
                        } }}
                    </span>
                    <h2>{{ $version->title ?: $version->change_summary ?: 'バージョン '.$version->version_number }}</h2>
                    @if($version->note)<p style="margin:0;">{{ $version->note }}</p>@endif
                    <div class="meta">VERSION {{ $version->version_number }} ／ 保存者 {{ $version->creator?->name ?? 'SYSTEM' }}</div>
                </div>
                <a class="button secondary" href="{{ route('projects.timeline-snapshots.show', [$project, $version]) }}">保存内容を見る</a>
            </article>
        @empty
            <div class="panel"><p>保存されたタイムラインはまだありません。</p></div>
        @endforelse
    </div>

    {{ $versions->links('components.pagination') }}
</section>
@endsection
