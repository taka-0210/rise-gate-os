@extends('layouts.app', ['title' => '帳票管理'])
@section('content')
<section class="stack">
    <div class="panel document-issuer-guide">
        <div>
            <div class="meta">帳票の発行元設定</div>
            <h2>会社情報・振込先を登録</h2>
            <p>見積書などに表示する会社名、住所、ロゴ、印章、振込先を設定します。</p>
        </div>
        <a class="button" href="{{ route('workspace-business-profile.edit') }}">事業者情報を設定</a>
    </div>
    <div><h1>帳票管理</h1><p>作成は各Projectから、確認と進捗管理はこの画面から行います。</p></div>
    <div class="document-grid">
        <a class="card stack document-card" href="{{ route('estimates.index') }}"><div class="meta">ESTIMATE</div><h2>見積書</h2><p>Projectのロードマップ・取り組み・タスクから作成します。</p><strong>{{ $estimateCount }}件</strong><span>下書き {{ $draftEstimateCount }}件</span></a>
        <article class="card stack document-card is-future"><div class="meta">DELIVERY</div><h2>納品書</h2><p>承認済み見積から納品内容を作成します。</p><span class="badge">次の段階で実装</span></article>
        <article class="card stack document-card is-future"><div class="meta">INVOICE</div><h2>請求書</h2><p>納品・契約内容から請求を作成します。</p><span class="badge">今後実装</span></article>
        <article class="card stack document-card is-future"><div class="meta">PAYMENT</div><h2>入金・消込</h2><p>請求額と入金データを照合します。</p><span class="badge">今後実装</span></article>
    </div>
</section>
<style>.document-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.document-card{text-decoration:none;color:inherit}.document-card strong{font-size:34px;color:var(--accent-dark)}.document-card.is-future{opacity:.72}@media(max-width:700px){.document-grid{grid-template-columns:1fr}}</style>
<style>
    .document-issuer-guide { display:flex; align-items:center; justify-content:space-between; gap:20px; }
    .document-issuer-guide p { margin-bottom:0; }
    .document-issuer-guide .button { flex:0 0 auto; }
    @media(max-width:700px) { .document-issuer-guide { align-items:stretch; flex-direction:column; } }
</style>
@endsection
