@extends('layouts.app')
@section('content')
<div class="page-header">
 <div><p class="eyebrow">COMPANY OS / NOTIFICATIONS</p><h1>通知</h1><p>仕事の変化を確認し、必要な場所へ戻るための入口です。</p></div>
 <a class="secondary" href="{{ route('notifications.policy.edit') }}">通知方針</a>
</div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
<section class="card" style="margin-bottom:18px">
 <h2>自分の通知設定</h2><p class="muted">アプリ内通知は常に利用できます。PushとEmailは本人が明示的に選びます。</p>
 <form method="POST" action="{{ route('notifications.preferences') }}" class="form-grid">
  @csrf
  <label><input type="checkbox" name="push_enabled" value="1" @checked($preference->push_enabled)> Push通知</label>
  <label><input type="checkbox" name="email_enabled" value="1" @checked($preference->email_enabled) @disabled(!auth()->user()->email_verified_at)> Email通知</label>
  <label><input type="checkbox" name="email_fallback_enabled" value="1" @checked($preference->email_fallback_enabled) @disabled(!auth()->user()->email_verified_at)> Email fallback</label>
  <label>個人Quiet開始（任意）<input type="time" name="quiet_starts_at" value="{{ $preference->quiet_starts_at }}"></label>
  <label>個人Quiet終了（任意）<input type="time" name="quiet_ends_at" value="{{ $preference->quiet_ends_at }}"></label>
  <button type="submit">保存</button><button type="button" class="secondary" data-enable-push>この端末でPushを有効にする</button><span data-push-status aria-live="polite"></span>
 </form>
</section>
<div class="grid">
@forelse($notifications as $notification)
 <article class="card" @if(!$notification->read_at_utc) style="border-left:4px solid var(--accent)" @endif>
  <p class="eyebrow">{{ strtoupper(str_replace('_', ' ', $notification->type)) }}</p><h2>{{ $notification->title }}</h2><p>{{ $notification->body }}</p>
  <p class="muted">{{ $notification->created_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST @if($notification->eligible_at_utc->isFuture())・通知予定 {{ $notification->eligible_at_utc->timezone('Asia/Tokyo')->format('m/d H:i') }}@endif</p>
  <a class="button" href="{{ route('notifications.open', $notification) }}">確認する</a>
  @if(!$notification->read_at_utc)<form method="POST" action="{{ route('notifications.read', $notification) }}" style="display:inline">@csrf<button class="secondary">既読</button></form>@endif
 </article>
@empty
 <article class="card"><h2>新しい通知はありません</h2><p>必要な変化がここに届きます。</p></article>
@endforelse
</div>
{{ $notifications->links() }}
@endsection
