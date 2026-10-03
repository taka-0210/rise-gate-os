@extends('layouts.app', ['title' => '年度経営方針 Source / Citation - '.$organization->name])
@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell"><header class="amp-header"><div><p class="amp-kicker">SOURCE / CITATION PREPARATION</p><h1>Revision {{ $export['revision_no'] }} の引用単位</h1><p>正式版のどの文を引用したか確認するための35A standalone exportです。35 Retrieval Engineへの登録はまだ行いません。</p></div><a class="button secondary" href="{{ route('annual-management-policy.revisions.show',[$annualPolicy,$export['revision_no']]) }}">Revisionへ戻る</a></header>
 <div class="panel stack"><div><strong>Mode</strong> {{ $export['mode'] }} / <strong>Status</strong> {{ $export['approval_status'] }}</div><div class="amp-code">Content SHA-256: {{ $export['content_hash'] }}</div><div class="meta">評価日 {{ data_get($export,'currentness.evaluated_on') }} / {{ data_get($export,'currentness.timezone') }} / Period version {{ data_get($export,'currentness.period_version') }} / Relation version {{ data_get($export,'currentness.relation_version') }}</div></div>
 <div class="amp-sections">@forelse($export['units'] as $unit)<article class="amp-section"><p class="amp-kicker">{{ strtoupper($unit['node_type']) }} / {{ strtoupper($unit['element_kind']) }}</p><div class="amp-prose">{{ $unit['text'] }}</div><div class="meta">Unicode code point {{ data_get($unit,'range.start') }} – {{ data_get($unit,'range.end') }}</div><div class="amp-code">Text SHA-256: {{ $unit['text_hash'] }}</div></article>@empty<div class="amp-empty">引用できる本文はありません。</div>@endforelse</div>
</section>
@endsection
