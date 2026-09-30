@extends('layouts.app', ['title' => '理念・Vision・方針 - '.$organization->name])

@section('content')
@include('management-design._styles')
<div class="mdc-shell mdc-directory">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <header class="mdc-directory__header">
        <p class="mdc-kicker">MANAGEMENT DESIGN / COMPANY CONTEXT</p>
        <h1>会社の言葉を、<br>正本として読む。</h1>
        <p class="mdc-lead">理念、向かう未来、判断の方向。日々の仕事の背景にある会社の考えを、更新履歴のある正式な内容として残します。</p>
        @if($canManagePermissions)
            <div class="mdc-actions" style="margin-top:24px"><a class="button secondary" href="{{ route('management-design.permissions') }}">閲覧・編集権限を管理</a></div>
        @endif
    </header>
    <section class="mdc-directory__list" aria-label="理念・Vision・方針">
        @foreach($types as $entry)
            @if($entry['can_view'])
                <a class="mdc-directory__item mdc-directory__item--{{ $entry['type'] }}" href="{{ route('management-design.show', $entry['type']) }}">
                    <span class="mdc-directory__code">{{ strtoupper($entry['type']) }}</span>
                    <span>
                        <h2>{{ $entry['presentation']['label'] }}</h2>
                        <p>{{ $entry['presentation']['description'] }}</p>
                        <small class="mdc-status">{{ $entry['item'] ? 'Revision '.$entry['item']->version : '正本は未登録' }}</small>
                    </span>
                    <span class="mdc-directory__arrow" aria-hidden="true">→</span>
                </a>
            @else
                <div class="mdc-directory__item mdc-directory__item--locked">
                    <span class="mdc-directory__code">{{ strtoupper($entry['type']) }}</span>
                    <span><h2>{{ $entry['presentation']['label'] }}</h2><p>現在、この内容を閲覧する権限はありません。</p></span>
                    <span class="mdc-status">権限なし</span>
                </div>
            @endif
        @endforeach
    </section>
</div>
@endsection
