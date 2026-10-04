<div class="ccr-annual-period ccr-reveal">
    <div><p>COMPANY PERIOD</p><h3>{{ $annual['period_label'] }}</h3><span>{{ str_replace('-', '/', $annual['starts_on']) }} — {{ str_replace('-', '/', $annual['ends_on']) }} / {{ $annual['timezone'] }}</span></div>
    <p><span>{{ strtoupper($annual['approval_status']) }}</span><strong>{{ $annual['lifecycle_label'] }}</strong><small>正式Revision {{ $chapter->revisionNo }}</small></p>
</div>
<section class="ccr-annual-lead ccr-reveal"><p>年度経営方針</p><h3>{!! nl2br(e($chapter->statement ?? '')) !!}</h3></section>
<div class="ccr-annual-context">
    @if($annual['purpose'])<section class="ccr-reveal"><p>今期、何を実現したいのか</p><div>{!! nl2br(e($annual['purpose'])) !!}</div></section>@endif
    @if($annual['background'])<section class="ccr-reveal"><p>この方針を定める背景</p><div>{!! nl2br(e($annual['background'])) !!}</div></section>@endif
</div>
@if(count($annual['themes']) > 0)
    <nav class="ccr-annual-subnav" aria-label="年度経営方針の章内目次">
        @foreach($annual['themes'] as $theme)<a href="#annual-theme-{{ $loop->iteration }}">重点テーマ {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</a>@endforeach
        @if(count($annual['departments']) > 0)<a href="#annual-departments">部署方針</a>@endif
    </nav>
    <div class="ccr-themes">
        @foreach($annual['themes'] as $theme)
            <section class="ccr-theme" id="annual-theme-{{ $loop->iteration }}">
                <header class="ccr-reveal"><p>重点テーマ <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span></p><h3>{{ $theme['statement'] }}</h3>@if($theme['explanation'])<div>{!! nl2br(e($theme['explanation'])) !!}</div>@endif</header>
                @if(count($theme['priorities']) > 0)
                    <div class="ccr-priorities">
                        @foreach($theme['priorities'] as $priority)
                            <section class="ccr-reveal"><p>優先方針 {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</p><h4>{{ $priority['statement'] }}</h4>@if($priority['explanation'])<span>{!! nl2br(e($priority['explanation'])) !!}</span>@endif</section>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endif
@if(count($annual['departments']) > 0)
    <section class="ccr-departments" id="annual-departments">
        <header class="ccr-reveal"><p>部署方針</p><h3>それぞれの場所から、同じ今期へ向かう。</h3></header>
        <div>
            @foreach($annual['departments'] as $department)
                <section class="ccr-reveal">
                    <p>{{ $department['name'] }}</p>
                    @if($department['introduction'])<div>{{ $department['introduction'] }}</div>@endif
                    <div>@foreach($department['statements'] as $statement)<h4>{{ $statement['statement'] }}</h4>@if($statement['explanation'])<span>{!! nl2br(e($statement['explanation'])) !!}</span>@endif @endforeach</div>
                </section>
            @endforeach
        </div>
    </section>
@endif
