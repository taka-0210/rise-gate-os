@props([
    'company',
    'title',
    'description' => null,
    'value' => null,
    'position' => null,
    'status' => null,
])

<header {{ $attributes->class(['company-context-hero']) }} data-company-context-hero data-context-reveal>
    <div class="company-context-hero__atmosphere" aria-hidden="true">
        <span></span><span></span><span></span>
    </div>
    <div class="company-context-hero__topline">
        <p class="company-context-hero__company">{{ $company }}</p>
        @isset($actions)
            <div class="company-context-print-hidden">{{ $actions }}</div>
        @endisset
    </div>
    <div class="company-context-hero__content">
        <p class="company-context-hero__kicker">BUSINESS DOMAIN / COMPANY CONTEXT</p>
        <h1>{{ $title }}</h1>
        @if($status)<span class="company-context-hero__status">{{ $status }}</span>@endif
        @if(filled($description))<p class="company-context-hero__lead">{{ $description }}</p>@endif
    </div>
    @if(filled($position))
        <p class="company-context-hero__position"><span>POSITION</span>{{ $position }}</p>
    @endif
    @if(filled($value))
        <a class="company-context-hero__continue company-context-print-hidden" href="#business-value">
            <span>この事業の価値を読む</span><span aria-hidden="true">↓</span>
        </a>
    @elseif(filled($description))
        <a class="company-context-hero__continue company-context-print-hidden" href="#business-story">
            <span>この事業を読む</span><span aria-hidden="true">↓</span>
        </a>
    @endif
</header>
