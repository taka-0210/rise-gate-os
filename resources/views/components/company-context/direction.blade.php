@props(['label', 'description' => null, 'memo' => null])

<div {{ $attributes->class(['company-context-direction']) }}>
    <div>
        <div class=company-context-direction__label>{{ $label }}</div>
        @if($description)<p class=company-context-direction__description>{{ $description }}</p>@endif
    </div>
    <span class=company-context-direction__badge>今後の方向性</span>
    @if($memo)<p class=company-context-direction__memo>{{ $memo }}</p>@endif
</div>
