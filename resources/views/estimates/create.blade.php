@extends('layouts.app', ['title' => '見積書を作成 - '.$project->name])
@section('content')
@php $rows=collect([['type'=>'manual','id'=>null,'title'=>$project->name.' 一式','level'=>0]]); foreach($project->roadmaps as $roadmap){$rows->push(['type'=>'roadmap','id'=>$roadmap->id,'title'=>$roadmap->title,'level'=>0]);foreach($roadmap->improvements as $improvement){$rows->push(['type'=>'improvement','id'=>$improvement->id,'title'=>$improvement->title,'level'=>1]);foreach($improvement->tasks as $task)$rows->push(['type'=>'task','id'=>$task->id,'title'=>$task->title,'level'=>2]);}} foreach($project->improvements->whereNull('roadmap_id') as $improvement){$rows->push(['type'=>'improvement','id'=>$improvement->id,'title'=>$improvement->title,'level'=>0]);foreach($improvement->tasks as $task)$rows->push(['type'=>'task','id'=>$task->id,'title'=>$task->title,'level'=>1]);} @endphp
<section class="stack">
    <div><div class="meta">{{ $project->client->name }} / {{ $project->name }}</div><h1>見積書を作成</h1><p>見積へ載せる項目を選び、数量と単価を入力します。保存後はProject側を変更しても見積内容は変わりません。</p></div>
    @if($errors->any())<div class="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form id="estimate-form" method="POST" action="{{ route('projects.estimates.store',$project) }}" class="stack">@csrf
        <section class="card stack"><h2>基本情報</h2><div class="base-grid"><div class="field span-2"><label>件名 <span class="required-mark">必須</span></label><input name="title" value="{{ old('title',$project->name.' 御見積') }}" required></div><div class="field"><label>発行日</label><input type="date" name="issued_on" value="{{ old('issued_on',now()->toDateString()) }}" required></div><div class="field"><label>有効期限</label><input type="date" name="valid_until" value="{{ old('valid_until',now()->addMonth()->toDateString()) }}"></div></div></section>
        <section class="card stack"><div><h2>見積明細</h2><p class="meta">一式を選ぶと、ほかの選択項目は金額に加算せず「作業範囲」として見積書へ記載します。</p></div><div class="scope-actions"><button class="button secondary" type="button" id="select-all-scope">作業範囲をすべて選択</button><button class="button secondary" type="button" id="clear-all-scope">すべて解除</button><span id="package-mode-note">ロードマップや取り組みを選ぶと、配下もまとめて選択できます。</span></div><div class="estimate-items">
            @forelse($rows as $index=>$row)<div class="estimate-item level-{{ $row['level'] }} @if($row['type']==='manual') package-item @endif" data-type="{{ $row['type'] }}" data-level="{{ $row['level'] }}"><input type="hidden" name="items[{{ $index }}][selected]" value="0"><label class="select-item"><input class="item-check" type="checkbox" name="items[{{ $index }}][selected]" value="1" @checked(old("items.$index.selected"))><span class="type type-{{ $row['type'] }}">{{ ['manual'=>'一式見積','roadmap'=>'ロードマップ','improvement'=>'取り組み','task'=>'タスク'][$row['type']] }}</span></label><input type="hidden" name="items[{{ $index }}][source_type]" value="{{ $row['type'] }}"><input type="hidden" name="items[{{ $index }}][source_id]" value="{{ $row['id'] }}"><div class="field description"><label>{{ $row['type']==='manual' ? '明細名' : '作業内容' }}</label><input name="items[{{ $index }}][description]" value="{{ old("items.$index.description",$row['title']) }}" required></div><div class="field small price-field"><label>数量</label><input type="number" step="0.001" min="0.001" name="items[{{ $index }}][quantity]" value="{{ old("items.$index.quantity",1) }}" required></div><div class="field small price-field"><label>単位</label><input name="items[{{ $index }}][unit]" value="{{ old("items.$index.unit",'式') }}" required></div><div class="field price price-field"><label>単価（円）</label><input type="number" min="0" name="items[{{ $index }}][unit_price]" value="{{ old("items.$index.unit_price",0) }}" required></div><div class="field tax price-field"><label>税率</label><select name="items[{{ $index }}][tax_rate]"><option value="10">10%</option><option value="8">8%</option><option value="0">0%</option></select></div><div class="scope-only-label">作業範囲として記載</div></div>
            @empty<p>見積明細にできる項目がありません。先にロードマップ・取り組み・タスクを作成してください。</p>@endforelse
        </div></section>
        <section class="card stack"><div class="base-grid"><div class="field"><label>値引き（円）</label><input id="estimate-discount" type="number" name="discount" min="0" value="{{ old('discount',0) }}"></div><div class="field span-2"><label>備考・支払条件</label><textarea name="notes" rows="4">{{ old('notes') }}</textarea></div></div></section>
        <section class="estimate-live-total" aria-live="polite"><div><span>小計</span><strong id="estimate-subtotal">￥0</strong></div><div><span>値引き</span><strong id="estimate-discount-display">−￥0</strong></div><div><span>値引き後</span><strong id="estimate-after-discount">￥0</strong></div><div><span>消費税</span><strong id="estimate-tax">￥0</strong></div><div class="grand"><span>合計金額</span><strong id="estimate-total">￥0</strong></div></section>
        <div class="actions"><button type="submit">見積書を下書き保存</button><a class="button secondary" href="{{ route('projects.show',$project) }}">Projectへ戻る</a></div>
    </form>
</section>
<style>.base-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.span-2{grid-column:1/-1}.required-mark{padding:2px 6px;border-radius:999px;background:#fff0ed;color:#a84e3c;font-size:10px}.scope-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.scope-actions span{font-size:13px;color:#61717a}.estimate-items{display:grid;gap:8px}.estimate-item{display:grid;grid-template-columns:135px minmax(220px,1fr) 85px 75px 120px 80px;gap:8px;align-items:end;padding:10px;border:1px solid var(--line);border-radius:8px;background:#fff}.estimate-item.package-item{margin-bottom:12px;border:2px solid #315d70;background:#f2f7f8}.estimate-item.level-1{margin-left:24px}.estimate-item.level-2{margin-left:48px}.select-item{display:flex;align-items:center;gap:7px;min-height:42px}.select-item input{width:auto}.type{font-size:11px;font-weight:800}.type-manual{color:#173f50}.type-roadmap{color:#245ca6}.type-improvement{color:#23845c}.type-task{color:#b5523d}.estimate-item .field{margin:0}.scope-only-label{display:none;grid-column:3/-1;align-self:center;padding:10px;border-radius:8px;background:#eaf4f2;color:#246047;font-weight:800}.package-mode .estimate-item:not(.package-item) .price-field{display:none}.package-mode .estimate-item:not(.package-item) .scope-only-label{display:block}.estimate-live-total{position:sticky;bottom:10px;z-index:5;display:grid;grid-template-columns:repeat(5,minmax(110px,1fr));gap:1px;padding:8px;background:#173f50;border-radius:12px;box-shadow:0 10px 30px rgba(14,40,50,.22)}.estimate-live-total div{display:grid;gap:4px;padding:10px 14px;background:#fff}.estimate-live-total div:first-child{border-radius:7px 0 0 7px}.estimate-live-total .grand{border-radius:0 7px 7px 0;background:#eaf4f2}.estimate-live-total span{font-size:12px;color:#61717a}.estimate-live-total strong{font-size:18px;color:#173f50}.estimate-live-total .grand strong{font-size:22px}@media(max-width:900px){.estimate-item{grid-template-columns:1fr 1fr}.description{grid-column:1/-1}.scope-only-label{grid-column:1/-1}.estimate-item.level-1,.estimate-item.level-2{margin-left:0}.base-grid{grid-template-columns:1fr}.span-2{grid-column:auto}.estimate-live-total{position:static;grid-template-columns:1fr 1fr}.estimate-live-total .grand{grid-column:1/-1}}</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const yen = value => '￥' + Math.round(value).toLocaleString('ja-JP');
    const calculate = () => {
        const packageSelected = document.querySelector('.package-item .item-check')?.checked;
        document.getElementById('estimate-form').classList.toggle('package-mode', Boolean(packageSelected));
        const lines = [...document.querySelectorAll('.estimate-item')].filter(row => row.querySelector('.item-check')?.checked && (!packageSelected || row.classList.contains('package-item'))).map(row => {
            const quantity = Number(row.querySelector('[name$="[quantity]"]')?.value || 0);
            const price = Number(row.querySelector('[name$="[unit_price]"]')?.value || 0);
            const rate = Number(row.querySelector('[name$="[tax_rate]"]')?.value || 0);
            return { amount: Math.round(quantity * price), rate };
        });
        const subtotal = lines.reduce((sum, line) => sum + line.amount, 0);
        const discount = Math.min(Math.max(0, Number(document.getElementById('estimate-discount')?.value || 0)), subtotal);
        const ratio = subtotal > 0 ? (subtotal - discount) / subtotal : 0;
        const tax = Math.floor(lines.reduce((sum, line) => sum + line.amount * ratio * line.rate / 100, 0));
        document.getElementById('estimate-subtotal').textContent = yen(subtotal);
        document.getElementById('estimate-discount-display').textContent = '−' + yen(discount);
        document.getElementById('estimate-after-discount').textContent = yen(subtotal - discount);
        document.getElementById('estimate-tax').textContent = yen(tax);
        document.getElementById('estimate-total').textContent = yen(subtotal - discount + tax);
    };
    document.getElementById('estimate-form').addEventListener('input', calculate);
    document.getElementById('estimate-form').addEventListener('change', calculate);
    const scopeRows = [...document.querySelectorAll('.estimate-item:not(.package-item)')];
    document.getElementById('select-all-scope').addEventListener('click', () => { scopeRows.forEach(row => row.querySelector('.item-check').checked = true); calculate(); });
    document.getElementById('clear-all-scope').addEventListener('click', () => { scopeRows.forEach(row => row.querySelector('.item-check').checked = false); calculate(); });
    scopeRows.forEach((row, index) => row.querySelector('.item-check').addEventListener('change', event => {
        const level = Number(row.dataset.level);
        if (row.dataset.type === 'task') return;
        for (let cursor = index + 1; cursor < scopeRows.length; cursor++) {
            const child = scopeRows[cursor];
            if (Number(child.dataset.level) <= level) break;
            child.querySelector('.item-check').checked = event.target.checked;
        }
    }));
    calculate();
});
</script>
@endsection
