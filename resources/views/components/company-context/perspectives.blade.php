@props(['axes'])

<div class="company-context-perspectives" data-context-deck>
    <div class="company-context-perspectives__tabs company-context-print-hidden" role="tablist" aria-label="事業の5つの視点">
        @foreach($axes as $axis)
            <button
                type="button"
                role="tab"
                id="context-tab-{{ $loop->index }}"
                aria-controls="context-panel-{{ $loop->index }}"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                data-context-tab
            >
                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                {{ $axis['code'] }}
            </button>
        @endforeach
    </div>
    <div class="company-context-perspectives__deck">
        @foreach($axes as $axis)
            <article
                class="company-context-perspective"
                role="tabpanel"
                id="context-panel-{{ $loop->index }}"
                aria-labelledby="context-tab-{{ $loop->index }}"
                data-context-panel
            >
                <div class="company-context-perspective__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                <div class="company-context-perspective__copy">
                    <p class="company-context-perspective__code">{{ $axis['code'] }}</p>
                    <h3>{{ $axis['label'] }}</h3>
                    <p>{{ $axis['value'] }}</p>
                </div>
            </article>
        @endforeach
    </div>
    <p class="company-context-perspectives__hint company-context-print-hidden">視点を選んで、事業を異なる角度から見る</p>
</div>
