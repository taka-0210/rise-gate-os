@extends('layouts.app', ['title' => $estimate->estimate_number])
@section('content')
@php $issuer=$estimate->issuer_snapshot;$client=$estimate->client_snapshot;$issuerName=$issuer['trade_name']??$issuer['legal_name']??$issuer['workspace_name']; @endphp
<section class="stack estimate-page">
    <div class="estimate-controls"><div><div class="meta">見積書 / {{ $estimate->estimate_number }}</div><h1>{{ $estimate->title }}</h1></div><div class="actions"><a class="button secondary" href="{{ route('estimates.index') }}">見積一覧</a><form method="POST" action="{{ route('estimates.duplicate', $estimate) }}">@csrf<button class="button secondary" type="submit">複製して下書き保存</button></form><button type="button" onclick="window.print()">印刷・PDF保存</button></div></div>
    @if(session('status'))<div class="panel">{{ session('status') }}</div>@endif
    <main class="estimate-sheet">
        <header class="estimate-header"><div>@if(!empty($issuer['logo_path']))<img class="estimate-logo" src="{{ route('estimates.media',[$estimate,'logo']) }}" alt="{{ $issuerName }}">@else<strong class="issuer-name">{{ $issuerName }}</strong>@endif</div><div class="estimate-title"><h2>御 見 積 書</h2><div>No. {{ $estimate->estimate_number }}</div><div>発行日 {{ $estimate->issued_on->format('Y年n月j日') }}</div></div></header>
        <section class="estimate-parties">
            <div>
                <h3>{{ $client['name'] }} 御中</h3>
                <p>件名：{{ $estimate->title }}</p>
                <p>有効期限：{{ $estimate->valid_until?->format('Y年n月j日') ?? '設定なし' }}</p>
            </div>
            <div class="issuer">
                <strong>{{ $issuer['legal_name'] ?? $issuerName }}</strong>
                @if(!empty($issuer['postal_code']))
                    <div>〒{{ $issuer['postal_code'] }}</div>
                @endif
                <div>{{ $issuer['address_line1'] ?? '' }} {{ $issuer['address_line2'] ?? '' }}</div>
                @if(!empty($issuer['phone']))
                    <div>TEL {{ $issuer['phone'] }}</div>
                @endif
                @if(!empty($issuer['invoice_registration_number']))
                    <div>登録番号 {{ $issuer['invoice_registration_number'] }}</div>
                @endif
                @if(!empty($issuer['seal_path']))
                    <img class="estimate-seal" src="{{ route('estimates.media', [$estimate, 'seal']) }}" alt="印章">
                @endif
            </div>
        </section>
        <div class="grand-total"><span>お見積金額（税込）</span><strong>￥{{ number_format($estimate->total) }}</strong></div>
        <table class="items"><thead><tr><th>No.</th><th>品名・作業内容</th><th>数量</th><th>単位</th><th>単価</th><th>金額</th></tr></thead><tbody>@foreach($estimate->items as $item)<tr><td>{{ $loop->iteration }}</td><td>{{ $item->description }}</td><td>{{ rtrim(rtrim(number_format((float)$item->quantity,3,'.',''),'0'),'.') }}</td><td>{{ $item->unit }}</td><td class="money">{{ number_format($item->unit_price) }}</td><td class="money">{{ number_format($item->amount) }}</td></tr>@endforeach</tbody></table>
        <div class="totals"><dl><dt>小計</dt><dd>￥{{ number_format($estimate->subtotal) }}</dd>@if($estimate->discount)<dt>値引き</dt><dd>－￥{{ number_format($estimate->discount) }}</dd>@endif<dt>消費税</dt><dd>￥{{ number_format($estimate->tax_amount) }}</dd><dt class="total">合計</dt><dd class="total">￥{{ number_format($estimate->total) }}</dd></dl></div>
        @if(!empty($issuer['bank_account']))@php $bank=$issuer['bank_account']; @endphp<section class="estimate-note"><strong>お振込先</strong><div>{{ $bank['bank_name'] }} {{ $bank['branch_name']??'' }} / {{ ['ordinary'=>'普通','current'=>'当座','savings'=>'貯蓄'][$bank['account_type']]??$bank['account_type'] }} {{ $bank['account_number'] }} / {{ $bank['account_holder'] }}</div></section>@endif
        @if($estimate->notes||!empty($issuer['document_note']))<section class="estimate-note"><strong>備考</strong><div>{!! nl2br(e($estimate->notes ?: $issuer['document_note'])) !!}</div></section>@endif
    </main>
</section>
<style>.estimate-controls{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.estimate-sheet{width:min(210mm,100%);min-height:297mm;margin:auto;padding:18mm;background:#fff;box-shadow:0 10px 35px rgba(20,40,50,.12)}.estimate-header,.estimate-parties{display:flex;justify-content:space-between;gap:24px}.estimate-logo{max-width:210px;max-height:80px;object-fit:contain}.issuer-name{font-size:24px;color:var(--accent-dark)}.estimate-title{text-align:right}.estimate-title h2{font-size:30px;letter-spacing:.25em}.estimate-parties{margin-top:24px}.estimate-parties h3{padding-bottom:8px;border-bottom:1px solid #172d38}.issuer{position:relative;min-width:280px;line-height:1.6}.estimate-seal{position:absolute;right:0;bottom:-25px;width:82px;height:82px;object-fit:contain;opacity:.88}.grand-total{display:flex;justify-content:space-between;align-items:center;margin:32px 0 20px;padding:12px 16px;border-top:2px solid #173f50;border-bottom:2px solid #173f50}.grand-total strong{font-size:26px}.items{width:100%;border-collapse:collapse}.items th,.items td{padding:9px;border:1px solid #bfcbd1}.items th{background:#edf3f5}.money{text-align:right}.totals{display:flex;justify-content:flex-end}.totals dl{display:grid;grid-template-columns:120px 150px;margin:12px 0}.totals dt,.totals dd{margin:0;padding:7px;border-bottom:1px solid #d5dee2}.totals dd{text-align:right}.totals .total{font-size:17px;font-weight:900}.estimate-note{margin-top:18px;padding:12px;border:1px solid #ccd7dc;line-height:1.7}@page{size:A4 portrait;margin:0}@media print{.topbar,.estimate-controls,.panel{display:none!important}.shell,.main,.estimate-page{display:block;width:auto;margin:0;padding:0}.estimate-sheet{width:210mm;min-height:297mm;margin:0;padding:15mm;box-shadow:none}}@media(max-width:700px){.estimate-controls,.estimate-parties{display:grid}.estimate-sheet{padding:20px}.issuer{min-width:0}}</style>
@endsection
