@extends('layouts.app')
@section('title','Quick Capture')
@section('content')
<section class="card"><p class="eyebrow">QUICK CAPTURE</p><h1>Company OSへ預ける</h1><p>整理は後で大丈夫です。何を、誰に、いつ知らせるかだけを記録します。</p>
@if($errors->any())<div class="alert">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('captures.store') }}" id="capture-form">@csrf<input type="hidden" name="operation_id" value="{{ $operationId }}"><label>何を<textarea name="body" required maxlength="4000">{{ old('body') }}</textarea></label><label>種類<select name="type" id="capture-type"><option value="self">自分用</option><option value="request">誰かへの依頼</option><option value="tell_later">あとで伝える</option></select></label><label id="recipient-field">誰に<select name="recipient_user_id">@foreach($recipients as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }}</option>@endforeach</select></label><label>いつ知らせるか<select name="notification_timing" id="capture-timing"><option value="now">今（Policy内の最早）</option><option value="next_window">次の通知窓</option><option value="specified">希望最早時刻</option></select></label><label id="notify-at-field" hidden>希望最早時刻<input type="datetime-local" name="notify_at"></label><button type="submit">COに預ける</button><p id="capture-result" role="alert" hidden></p></form></section>
<script>
const t=document.querySelector('#capture-type'),r=document.querySelector('#recipient-field'),n=document.querySelector('#capture-timing'),s=document.querySelector('#notify-at-field'),f=document.querySelector('#capture-form'),o=document.querySelector('#capture-result');
const sync=()=>{r.hidden=t.value==='self';s.hidden=n.value!=='specified'};
t.addEventListener('change',sync);n.addEventListener('change',sync);sync();
f.addEventListener('submit',async e=>{
    e.preventDefault();
    if(!navigator.onLine){o.hidden=false;o.textContent='オフラインのため保存していません。ネットワーク復帰後にもう一度押してください。';return}
    o.hidden=false;o.textContent='保存しています…';
    try{
        const response=await fetch(f.action,{method:'POST',body:new FormData(f),headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(response.ok){location.assign(response.url);return}
        if(response.status===422){const result=await response.json();o.textContent=Object.values(result.errors||{}).flat()[0]||'入力内容を確認してください（保存していません）。';return}
        throw new Error('save response was not confirmed');
    }catch(error){
        o.textContent='保存結果未確認です。同じ操作IDで結果を確認しています…';
        try{
            const operation=encodeURIComponent(f.elements.operation_id.value);
            const result=await fetch('{{ url('/company/capture-operations') }}/'+operation,{headers:{'Accept':'application/json'}});
            if(result.ok){const state=await result.json();if(state.status==='saved'&&state.url){location.assign(state.url);return}}
        }catch(ignored){}
        o.textContent='保存結果未確認です。通信復帰後に同じ画面のボタンで再試行してください。同じ操作IDのため二重保存されません。';
    }
});
</script>
@endsection
