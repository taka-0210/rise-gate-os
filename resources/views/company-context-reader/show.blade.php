@extends('layouts.app', ['title' => '会社の言葉 - '.$organization->name])

@section('content')
<link rel="stylesheet" href="{{ asset('css/company-context-reader.css') }}">
<div class="ccr" id="top" data-company-context-reader data-motion="on" data-brand-entry="waiting">
    <a class="ccr-skip" href="#ccr-reader">本文へ移動</a>
    <header class="ccr-toolbar" aria-label="Reader表示設定">
        <div><p>COMPANY CONTEXT READER</p><strong>会社の言葉</strong><span>理念から今期の重点まで、一つの流れで読みます。</span></div>
        <div class="ccr-toolbar__actions">
            <div class="ccr-brand-tuner" role="group" aria-label="Brand Visual位置の一時調整">
                <label>Visual X <output data-brand-output="x">-110px</output><input type="range" min="-320" max="80" step="10" value="-110" data-brand-setting="x"></label>
                <label>Size <output data-brand-output="size">100%</output><input type="range" min="80" max="140" step="5" value="100" data-brand-setting="size"></label>
            </div>
            @if(count($reader->annualOptions) > 0)
                <form method="GET" action="{{ route('company-context-reader.show') }}" class="ccr-period-selector">
                    <label for="ccr-annual">年度方針</label>
                    <select id="ccr-annual" name="annual" onchange="this.form.submit()">
                        <option value="">現在有効</option>
                        @foreach($reader->annualOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($reader->selectedAnnual === $option['value'])>{{ $option['label'] }}｜{{ $option['lifecycle'] }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit">表示</button></noscript>
                </form>
            @endif
            <div class="ccr-motion" role="group" aria-label="表示時の動き"><span>Motion</span><button type="button" data-motion-choice="on" aria-pressed="true">ON</button><button type="button" data-motion-choice="off" aria-pressed="false">OFF</button></div>
        </div>
    </header>

    @if($reader->selectedAnnual)
        @php($annualChapter = collect($reader->chapters)->first(fn ($entry) => $entry->key === 'annual'))
        @if($annualChapter && $annualChapter->annual['effective_status'] !== 'effective')
            <aside class="ccr-period-note"><strong>{{ $annualChapter->annual['lifecycle_label'] }}</strong><span>年度経営方針は選択した期間の正式Revisionです。理念・Vision・方針は現在の正本を表示しています。</span><a href="{{ route('company-context-reader.show') }}">現在有効へ戻る</a></aside>
        @endif
    @endif

    <details class="ccr-mobile-toc" data-mobile-toc>
        <summary><span data-current-chapter>{{ $reader->chapters[0]->ordinal }} {{ $reader->chapters[0]->label }}</span><span>目次</span></summary>
        <nav aria-label="会社の言葉 章目次">@foreach($reader->chapters as $chapter)<a href="#{{ $chapter->anchor }}"><small>{{ $chapter->ordinal }}</small><span>{{ $chapter->label }}</span></a>@endforeach</nav>
    </details>

    <div class="ccr-layout">
        <aside class="ccr-rail"><nav aria-label="会社の言葉 章目次"><p>CHAPTERS</p>
            @foreach($reader->chapters as $chapter)
                <a href="#{{ $chapter->anchor }}" data-toc-key="{{ $chapter->key }}" @if($loop->first) aria-current="true" @endif><small>{{ $chapter->ordinal }}</small><span><strong>{{ $chapter->label }}</strong><em>{{ $chapter->direction }}</em></span></a>
            @endforeach
        </nav></aside>
        <main id="ccr-reader" class="ccr-reader" tabindex="-1">
            <header class="ccr-cover">
                <p>{{ $organization->name }}</p><span>COMPANY CONTEXT READER</span><h1>会社の言葉</h1>
                <div>私たちが大切にすること、目指す未来、判断の方向、そして今期の重点を、一つの流れで読みます。</div>
                <nav aria-label="表紙の章目次">@foreach($reader->chapters as $chapter)<a href="#{{ $chapter->anchor }}">{{ $chapter->ordinal }} {{ $chapter->label }}</a>@endforeach</nav>
            </header>
            @foreach($reader->chapters as $chapter)
                @if(!$loop->first)
                    @php($previous = $reader->chapters[$loop->index - 1])
                    <div class="ccr-transition" aria-hidden="true"><span>FROM {{ $previous->direction }}</span><i></i><span>TO {{ $chapter->direction }}</span></div>
                @endif
                <section class="ccr-chapter ccr-chapter--{{ $chapter->key }}" id="{{ $chapter->anchor }}" data-chapter="{{ $chapter->key }}" data-chapter-label="{{ $chapter->ordinal }} {{ $chapter->label }}">
                    <header class="ccr-chapter__intro ccr-reveal"><p>{{ $chapter->ordinal }}</p><div><span>{{ $chapter->direction }}</span><h2>{{ $chapter->label }}</h2><em>{{ $chapter->question }}</em></div></header>
                    @if(!$chapter->available)
                        <div class="ccr-empty ccr-reveal"><strong>正本はまだ登録されていません。</strong><span>登録後、この章に正式な内容が表示されます。</span></div>
                    @elseif($chapter->key === 'annual')
                        @include('company-context-reader._annual', ['chapter' => $chapter, 'annual' => $chapter->annual])
                    @else
                        <div class="ccr-opening ccr-reveal">
                            @if($chapter->statement)<p class="ccr-statement">{!! nl2br(e($chapter->statement)) !!}</p>@endif
                            @if($chapter->horizon)<div class="ccr-horizon"><span>HORIZON</span><strong>{{ $chapter->horizon }}</strong></div>@endif
                            @if($chapter->explanation)<div class="ccr-prose"><p>{!! nl2br(e($chapter->explanation)) !!}</p></div>@endif
                        </div>
                        @if(count($chapter->sections) > 0)
                            <div class="ccr-sections ccr-sections--{{ $chapter->key }}">
                                @foreach($chapter->sections as $section)
                                    <section class="ccr-section ccr-reveal"><p>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</p><div>
                                        @if($section['title'])<h3>{{ $section['title'] }}</h3>@endif
                                        @if($section['body'])<strong>{!! nl2br(e($section['body'])) !!}</strong>@endif
                                        @if($section['explanation'])<span>{!! nl2br(e($section['explanation'])) !!}</span>@endif
                                        @if($section['horizon'])<small>Horizon｜{{ $section['horizon'] }}</small>@endif
                                    </div></section>
                                @endforeach
                            </div>
                        @endif
                    @endif
                    <footer class="ccr-document-info">
                        <span>@if($chapter->available)正式Revision {{ $chapter->revisionNo }}@if($chapter->sourceChangedAt) / {{ $chapter->sourceChangedAt }}@endif @if($chapter->documentStatus === 'archived') / 保管中@endif @else 正本未登録 @endif</span>
                        @if(count($chapter->managementLinks) > 0)<nav aria-label="{{ $chapter->label }}の管理">@foreach($chapter->managementLinks as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav>@endif
                    </footer>
                </section>
            @endforeach
            <footer class="ccr-end"><p>END OF READER</p><h2>必要な言葉へ、いつでも戻れます。</h2><nav aria-label="Reader終端の章目次">@foreach($reader->chapters as $chapter)<a href="#{{ $chapter->anchor }}">{{ $chapter->label }}</a>@endforeach</nav><a href="#top">最初へ戻る</a></footer>
        </main>
    </div>
    <div class="ccr-brand" data-brand-visual aria-hidden="true"><div class="ccr-brand__canvas" data-brand-canvas><img src="{{ asset('images/company-os-brand-symbol.svg') }}" alt="" width="2880" height="1620" data-brand-fallback></div></div>
</div>
<script src="{{ asset('js/company-context-reader.js') }}" defer></script>
@endsection
