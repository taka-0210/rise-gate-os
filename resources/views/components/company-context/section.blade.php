@props(['eyebrow', 'title'])

<section {{ $attributes->class(['company-context-section']) }}>
    <header class=company-context-section__heading>
        <span class=company-context-section__eyebrow>{{ $eyebrow }}</span>
        <h2>{{ $title }}</h2>
    </header>
    <div class=company-context-section__body>{{ $slot }}</div>
</section>
