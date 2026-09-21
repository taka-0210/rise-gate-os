@props(['label', 'title'])

<section {{ $attributes->class(['company-context-highlight']) }}>
    <span class=company-context-highlight__label>{{ $label }}</span>
    <h2>{{ $title }}</h2>
    <p>{{ $slot }}</p>
</section>
