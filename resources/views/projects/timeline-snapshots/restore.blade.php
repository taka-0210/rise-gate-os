@extends('layouts.app', ['title' => '保存履歴から復元 - '.$project->name])

@section('content')
<style>
    .restore-page{max-width:1120px;margin:0 auto}
    .restore-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .restore-card{padding:18px;border:1px solid var(--line);border-radius:10px;background:#fff}
    .restore-card h2{margin:0 0 12px;font-size:18px}
    .restore-counts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}
    .restore-counts span{padding:9px;border-radius:7px;background:#f3f6f7;font-size:12px}
    .restore-warning{border-color:#e1b596;background:#fff8f2}
    .restore-actions{display:flex;justify-content:flex-end;gap:10px}
    @media(max-width:760px){.restore-grid{grid-template-columns:1fr}.restore-actions{align-items:stretch;flex-direction:column}}
</style>

<section class="stack restore-page">
    <div class="page-head">
        <div>
            <div class="eyebrow">RESTORE TIMELINE / VERSION {{ $version->version_number }}</div>
            <h1>保存履歴から予定を復元</h1>
            <p>「{{ $version->title ?: $version->change_summary }}」の予定内容へ戻します。</p>
        </div>
        <a class="button secondary" href="{{ route('projects.timeline-snapshots.show', [$project, $version]) }}">保存履歴へ戻る</a>
    </div>

    <div class="panel restore-warning">
        <h2>復元されるもの</h2>
        <p>Project期間、ロードマップ、取り組み、タスク、予定工数、説明、並び順を復元します。復元直前の状態は自動保存されます。</p>
        <h2>復元されないもの</h2>
        <p>実績記録、実績工数、メンバー、見積、添付資料は変更しません。現在存在する項目の完了・進行中などの進捗状態も維持します。</p>
    </div>

    <div class="restore-grid">
        @foreach(['roadmaps' => 'ロードマップ', 'improvements' => '取り組み', 'tasks' => 'タスク'] as $key => $label)
            <article class="restore-card">
                <h2>{{ $label }}</h2>
                <div class="restore-counts">
                    <span>復元 {{ $changes[$key]['restore'] }}件</span>
                    <span>更新 {{ $changes[$key]['update'] }}件</span>
                    <span>削除 {{ $changes[$key]['remove'] }}件</span>
                    <span>変更なし {{ $changes[$key]['keep'] }}件</span>
                </div>
            </article>
        @endforeach
    </div>

    <form class="panel stack" method="POST" action="{{ route('projects.timeline-snapshots.restore', [$project, $version]) }}">
        @csrf
        <label class="check"><input type="checkbox" name="confirmed" value="1" required> 復元対象と、実績記録が変更されないことを確認しました。</label>
        @error('confirmed')<div class="error">{{ $message }}</div>@enderror
        <div class="restore-actions">
            <a class="button secondary" href="{{ route('projects.timeline-snapshots.show', [$project, $version]) }}">キャンセル</a>
            <button type="submit">復元前を自動保存して実行</button>
        </div>
    </form>
</section>
@endsection
