@extends('layouts.app', ['title' => $presentation['label'].' - '.$organization->name])

@section('content')
@include('management-design._styles')
@php($longStatement = mb_strlen((string) ($item?->statement ?? '')) > 240)
<article class="mdc-read mdc-read--{{ $type }}" data-mdc-type="{{ $type }}">
    <div class="mdc-shell mdc-actions mdc-actions--secondary mdc-print-hidden">
        <a class="button secondary" href="{{ route('management-design.index') }}">理念・Vision・方針</a>
        @if($item)<a class="button secondary" href="{{ route('management-design.history', $type) }}">History</a>@endif
        @if($canEdit && (!$item || $item->status === 'active'))<a class="button" href="{{ route('management-design.edit', $type) }}">内容を編集</a>@endif
    </div>
    @if(session('status'))<div class="mdc-shell notice">{{ session('status') }}</div>@endif
    @if($item?->status === 'archived')<div class="mdc-archive">この正本は保管中です。以下は保管時点のcurrent officialです。</div>@endif
    <header class="mdc-hero {{ $longStatement ? 'mdc-hero--long' : '' }}">
        <p class="mdc-kicker">{{ strtoupper($type) }} / COMPANY CONTEXT</p>
        <h1>{{ $presentation['label'] }}</h1>
        @if(filled($item?->statement))
            <p class="mdc-hero__statement">{{ $item->statement }}</p>
        @else
            <p class="mdc-hero__statement">{{ $item ? 'Sectionとともに、この会社の言葉を残します。' : '正本はまだ登録されていません。' }}</p>
        @endif
        @if(filled($item?->statement_explanation))<p class="mdc-hero__explanation">{{ $item->statement_explanation }}</p>@endif
        @if($type === 'vision' && filled($item?->horizon))<p class="mdc-hero__horizon">HORIZON / {{ $item->horizon }}</p>@endif
    </header>
    <div class="mdc-sections">
        @if($item && $item->sections->isNotEmpty())
            @foreach($item->sections as $section)
                <section class="mdc-section" id="section-{{ $section->public_id }}">
                    @if($type === 'vision' && filled($section->horizon))<p class="mdc-section__horizon">HORIZON / {{ $section->horizon }}</p>@endif
                    <h2>{{ $section->title }}</h2>
                    <p class="mdc-section__body">{{ $section->body }}</p>
                    @if(filled($section->explanation))<p class="mdc-section__explanation">{{ $section->explanation }}</p>@endif
                </section>
            @endforeach
        @else
            <div class="mdc-section--empty">Sectionはありません。0 Sectionでも正本として成立します。</div>
        @endif
    </div>
</article>
@endsection
