@extends('layouts.app', ['title' => $presentation['label'].' History - '.$organization->name])

@section('content')
@include('management-design._styles')
<section class="mdc-shell stack">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <header class="mdc-form__intro"><div><p class="mdc-kicker">{{ strtoupper($type) }} / IMMUTABLE HISTORY</p><h1>{{ $presentation['label'] }} History</h1><p>正本保存とLifecycle変更を、当時のSection一式とともに確認できます。</p></div><a class="button secondary" href="{{ route('management-design.show', $type) }}">読む画面へ</a></header>
    @if($canEdit)
        <div class="panel stack">
            <div><p class="mdc-kicker">LIFECYCLE</p><h2>{{ $item->status === 'active' ? '正本を保管する' : '同じStable IDで再開する' }}</h2><p>本文と過去Revisionは削除されません。状態変更も新しいRevisionとして記録します。</p></div>
            <form method="POST" action="{{ $item->status === 'active' ? route('management-design.archive', $type) : route('management-design.reopen', $type) }}" class="stack">
                @csrf
                <input type="hidden" name="request_id" value="{{ $item->status === 'active' ? $archiveRequestId : $reopenRequestId }}">
                <input type="hidden" name="expected_version" value="{{ $item->version }}">
                <div class="field"><label for="state-reason">理由（任意）</label><textarea id="state-reason" name="change_reason" rows="3" maxlength="2000"></textarea></div>
                <div><button class="{{ $item->status === 'active' ? 'danger' : '' }}" type="submit">{{ $item->status === 'active' ? '保管する' : '再開する' }}</button></div>
            </form>
        </div>
    @endif
    <div class="mdc-history" aria-label="Revision履歴">
        @foreach($revisions as $revision)
            <a class="mdc-history__row" href="{{ route('management-design.revisions.show', [$type, $revision->revision_no]) }}">
                <strong>Revision {{ $revision->revision_no }}</strong>
                <span>{{ $revision->changed_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i:s') }} JST</span>
                <span>{{ $revision->actor?->name ?? 'Deleted user' }}</span>
                <span>{{ $revision->change_reason ?: '変更理由なし' }} / {{ $revision->status }}</span>
            </a>
        @endforeach
    </div>
    {{ $revisions->links() }}
</section>
@endsection
