@extends('layouts.app', ['title' => '帳票管理'])
@section('content')
<section class="stack">
    <div><h1>帳票管理</h1><p>作成は各Projectから、確認と進捗管理はこの画面から行います。</p></div>
    <div class="document-grid">
        <a class="card stack document-card" href="{{ route('estimates.index') }}"><div class="meta">ESTIMATE</div><h2>見積書</h2><p>Projectのロードマップ・取り組み・タスクから作成します。</p><strong>{{ $estimateCount }}件</strong><span>下書き {{ $draftEstimateCount }}件</span></a>
        <article class="card stack document-card is-future"><div class="meta">DELIVERY</div><h2>納品書</h2><p>承認済み見積から納品内容を作成します。</p><span class="badge">次の段階で実装</span></article>
        <article class="card stack document-card is-future"><div class="meta">INVOICE</div><h2>請求書</h2><p>納品・契約内容から請求を作成します。</p><span class="badge">今後実装</span></article>
        <article class="card stack document-card is-future"><div class="meta">PAYMENT</div><h2>入金・消込</h2><p>請求額と入金データを照合します。</p><span class="badge">今後実装</span></article>
    </div>
</section>
<style>.document-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.document-card{text-decoration:none;color:inherit}.document-card strong{font-size:34px;color:var(--accent-dark)}.document-card.is-future{opacity:.72}@media(max-width:700px){.document-grid{grid-template-columns:1fr}}</style>
@endsection
