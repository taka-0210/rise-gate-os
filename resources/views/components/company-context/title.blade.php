@props(['company' => null, 'title', 'kicker' => 'COMPANY CONTEXT', 'status' => null])

<header class=company-context-title>
    <div>
        @if(filled($company))<p class=company-context-title__company>{{ $company }}</p>@endif
        <p class=company-context-title__kicker>{{ $kicker }}</p>
        <h1>{{ $title }}</h1>
        @if($status)<span class=company-context-title__status>{{ $status }}</span>@endif
    </div>
    @isset($actions)
        <div class=company-context-print-hidden>{{ $actions }}</div>
    @endisset
</header>
