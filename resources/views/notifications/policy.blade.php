@extends('layouts.app')
@section('content')
<div class="page-header"><div><p class="eyebrow">ORGANIZATION / NOTIFICATION POLICY</p><h1>通知方針</h1><p>会社として通知できる時間帯をOwner / Adminが確定します。</p></div></div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
<form method="POST" action="{{ route('notifications.policy.update') }}" class="card">
@csrf @method('PUT')
<label><input type="hidden" name="is_confirmed" value="0"><input type="checkbox" name="is_confirmed" value="1" @checked($policy->is_confirmed)> この方針を有効にする</label>
<p class="muted">未確定の間は候補時間を外部通知へ使用しません。祝日・例外稼働日は明示登録し、推測しません。</p>
@php($windows=$policy->weekday_windows?:[])
@foreach([1=>'月',2=>'火',3=>'水',4=>'木',5=>'金',6=>'土',7=>'日'] as $day=>$label)
@php($window=$windows[(string)$day]??['enabled'=>$day<=5,'start'=>'09:00','end'=>'18:00'])
<div class="form-grid" style="grid-template-columns:80px 1fr 1fr;align-items:end">
 <label><input type="hidden" name="weekday_windows[{{ $day }}][enabled]" value="0"><input type="checkbox" name="weekday_windows[{{ $day }}][enabled]" value="1" @checked($window['enabled']??false)> {{ $label }}</label>
 <label>開始<input type="time" name="weekday_windows[{{ $day }}][start]" value="{{ $window['start']??'09:00' }}"></label>
 <label>終了<input type="time" name="weekday_windows[{{ $day }}][end]" value="{{ $window['end']??'18:00' }}"></label>
</div>
@endforeach
<label style='display:block;margin-top:20px'>祝日・例外稼働日
<textarea name='calendar_dates' rows='6' placeholder='2026-12-29,holiday,年末休業&#10;2027-01-09,working_exception,臨時稼働'>@foreach($calendarDates as $date){{ $date->calendar_date->format('Y-m-d') }},{{ $date->kind }},{{ $date->label }}@if(!$loop->last)&#10;@endif @endforeach</textarea>
</label>
<p class='muted'>祝日は自動推測しません。空欄で明示登録なし、holidayは休業、working_exceptionは例外稼働です。</p>
<button type="submit">方針を保存</button>
</form>
@endsection
