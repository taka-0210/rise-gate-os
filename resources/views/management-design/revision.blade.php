@extends('layouts.app', ['title' => $presentation['label'].' Revision '.$revision->revision_no])

@section('content')
@include('management-design._styles')
@php($snapshot = $revision->snapshot['item'] ?? [])
<article class="mdc-shell stack">
    <header class="mdc-form__intro"><div><p class="mdc-kicker">{{ strtoupper($type) }} / REVISION {{ $revision->revision_no }}</p><h1>{{ $presentation['label'] }}</h1><p>{{ $revision->changed_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i:s') }} JST / {{ $revision->actor?->name ?? 'Deleted user' }} / {{ $revision->status }}</p></div><a class="button secondary" href="{{ route('management-design.history', $type) }}">Historyへ戻る</a></header>
    @if(filled($snapshot['statement'] ?? null))<p class="mdc-hero__statement">{{ $snapshot['statement'] }}</p>@endif
    @if(filled($snapshot['statement_explanation'] ?? null))<p class="mdc-hero__explanation">{{ $snapshot['statement_explanation'] }}</p>@endif
    @if($type === 'vision' && filled($snapshot['horizon'] ?? null))<p class="mdc-section__horizon">HORIZON / {{ $snapshot['horizon'] }}</p>@endif
    <div>
        @forelse($snapshot['sections'] ?? [] as $section)
            <section class="mdc-revision-section">
                @if($type === 'vision' && filled($section['horizon'] ?? null))<p class="mdc-section__horizon">HORIZON / {{ $section['horizon'] }}</p>@endif
                <h2>{{ $section['title'] }}</h2><p class="mdc-section__body">{{ $section['body'] }}</p>
                @if(filled($section['explanation'] ?? null))<p class="mdc-section__explanation">{{ $section['explanation'] }}</p>@endif
            </section>
        @empty<div class="mdc-section--empty">このRevisionにSectionはありません。</div>@endforelse
    </div>
    @if(filled($revision->change_reason))<div class="panel"><p class="mdc-kicker">CHANGE REASON</p><p>{{ $revision->change_reason }}</p></div>@endif
</article>
@endsection
