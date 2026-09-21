@props(['axes'])

@php
    $coordinates = [
        'WHAT' => [280, 68],
        'WHO' => [482, 216],
        'VALUE' => [405, 455],
        'WHERE' => [155, 455],
        'POSITION' => [78, 216],
    ];
    $angles = [
        'WHAT' => -90,
        'WHO' => -18,
        'VALUE' => 54,
        'WHERE' => 126,
        'POSITION' => 198,
    ];
@endphp

<div class="company-context-perspectives" data-context-deck>
    <div class="company-context-perspectives__visual-column">
        <div class="company-context-orbit-visual" aria-hidden="true">
            <svg viewBox="0 0 560 560" focusable="false">
                <defs>
                    <radialGradient id="company-context-core-glow" cx="35%" cy="30%" r="70%">
                        <stop offset="0%" stop-color="#9ce9df" stop-opacity=".34"/>
                        <stop offset="42%" stop-color="#227984" stop-opacity=".3"/>
                        <stop offset="100%" stop-color="#061d26" stop-opacity=".06"/>
                    </radialGradient>
                </defs>
                <circle class="company-context-orbit-visual__field" cx="280" cy="280" r="250"/>
                <circle class="company-context-orbit-visual__ring company-context-orbit-visual__ring--outer" cx="280" cy="280" r="212"/>
                <circle class="company-context-orbit-visual__ring company-context-orbit-visual__ring--middle" cx="280" cy="280" r="153"/>
                <circle class="company-context-orbit-visual__ring company-context-orbit-visual__ring--inner" cx="280" cy="280" r="91"/>
                <g class="company-context-orbit-visual__focus" data-context-orbit-focus>
                    <circle cx="280" cy="280" r="212"/>
                </g>
                @foreach($axes as $axis)
                    @php([$x, $y] = $coordinates[$axis['code']] ?? [280, 68])
                    <line
                        class="company-context-orbit-visual__line {{ $loop->first ? 'is-active' : '' }}"
                        x1="280" y1="280" x2="{{ $x }}" y2="{{ $y }}"
                        data-context-orbit-line="{{ $loop->index }}"
                    />
                    <g
                        class="company-context-orbit-visual__node {{ $loop->first ? 'is-active' : '' }}"
                        transform="translate({{ $x }} {{ $y }})"
                        data-context-orbit-node="{{ $loop->index }}"
                    >
                        <circle class="company-context-orbit-visual__node-halo" r="30"/>
                        <circle class="company-context-orbit-visual__node-dot" r="5"/>
                        <text y="47" text-anchor="middle">{{ $axis['code'] }}</text>
                    </g>
                @endforeach
                <g class="company-context-orbit-visual__core">
                    <circle cx="280" cy="280" r="66" fill="url(#company-context-core-glow)"/>
                    <circle cx="280" cy="280" r="66"/>
                    <text x="280" y="272" text-anchor="middle">BUSINESS</text>
                    <text x="280" y="298" text-anchor="middle">DOMAIN</text>
                </g>
            </svg>
            <div class="company-context-orbit-visual__active">
                <span data-context-active-number>01</span>
                <strong data-context-active-code>{{ $axes[0]['code'] }}</strong>
                <small data-context-active-label>{{ $axes[0]['label'] }}</small>
            </div>
        </div>

        <nav class="company-context-perspectives__tabs company-context-print-hidden" aria-label="事業の5つの視点">
            @foreach($axes as $axis)
                <button
                    type="button"
                    aria-controls="context-panel-{{ $loop->index }}"
                    @if($loop->first) aria-current="true" @endif
                    data-context-tab
                    data-context-code="{{ $axis['code'] }}"
                    data-context-label="{{ $axis['label'] }}"
                    data-context-angle="{{ $angles[$axis['code']] ?? -90 }}"
                >
                    <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    {{ $axis['code'] }}
                </button>
            @endforeach
        </nav>
    </div>

    <div class="company-context-perspectives__progress company-context-print-hidden" aria-hidden="true">
        <span data-context-axis-progress></span>
    </div>

    <div class="company-context-perspectives__panels">
        @foreach($axes as $axis)
            <article
                class="company-context-perspective {{ $loop->first ? 'is-active' : '' }}"
                id="context-panel-{{ $loop->index }}"
                aria-labelledby="context-perspective-heading-{{ $loop->index }}"
                data-context-panel
                data-context-index="{{ $loop->index }}"
            >
                <div class="company-context-perspective__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                <div class="company-context-perspective__copy">
                    <p class="company-context-perspective__code">{{ $axis['code'] }}</p>
                    <h3 id="context-perspective-heading-{{ $loop->index }}">{{ $axis['label'] }}</h3>
                    <p>{{ $axis['value'] }}</p>
                </div>
            </article>
        @endforeach
    </div>
</div>
