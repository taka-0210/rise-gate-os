@extends('layouts.app', ['title' => $domain->name.' Revision '.$revision->revision_no])

@section('content')
@php($snapshot = $revision->snapshot['domain'] ?? [])
<section class="stack">
    <div class="page-header"><div class="meta">BUSINESS DOMAIN / IMMUTABLE HISTORY</div><h1>{{ $domain->name }} / Revision {{ $revision->revision_no }}</h1><p>{{ $revision->changed_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i:s') }} JST / {{ $revision->actor?->name ?? 'Deleted user' }}</p></div>
    <div class="notice">これは保存時点の読み取り専用Snapshotです。現在値への復元機能ではありません。</div>
    <div class="panel stack"><div><strong>変更理由</strong><p>{{ $revision->change_reason ?: '作成' }}</p></div><div><strong>状態</strong><p>{{ $snapshot['status'] ?? '' }} / Revision {{ $snapshot['version'] ?? $revision->revision_no }}</p></div></div>
    <div class="panel stack">
        @foreach(['description' => '概要', 'what' => 'WHAT', 'who' => 'WHO', 'value' => 'VALUE', 'where' => 'WHERE', 'position' => 'POSITION', 'self_recognized_strengths' => '自社認識の強み'] as $key => $label)
            <div><div class="meta">{{ $label }}</div><p style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $snapshot[$key] ?? '未登録' }}</p></div>
        @endforeach
        <div><div class="meta">今後の方向性</div><p>{{ \App\Models\BusinessDomain::directionLabel($snapshot['direction'] ?? null) }}</p></div>
        <div><div class="meta">方向性メモ</div><p style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $snapshot['direction_memo'] ?? '未登録' }}</p></div>
    </div>
    <div class="panel stack"><h2>明細</h2>@forelse(($snapshot['items'] ?? []) as $item)<article class="card stack"><div><span class="badge">{{ $item['kind'] }}</span><h3>{{ $item['name'] }}</h3><p>{{ $item['description'] ?? '' }}</p></div>@foreach(($item['attributes'] ?? []) as $attribute)<div><strong>{{ strtoupper($attribute['axis']) }} / {{ $attribute['label'] }}</strong><p style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $attribute['value'] }}</p></div>@endforeach</article>@empty<p class="meta">明細なし</p>@endforelse</div>
    <div><a class="button secondary" href="{{ route('business-domains.show', $domain) }}">現在値へ戻る</a></div>
</section>
@endsection
