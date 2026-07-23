@php
    $width = 1000;
    $height = 260;
    $left = 55;
    $right = 24;
    $top = 20;
    $bottom = 42;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $allValues = collect($series)->flatMap(fn ($item) => $item['values'])->map(fn ($value) => (float) $value);
    $minimum = min(0, (float) ($allValues->min() ?? 0));
    $maximum = max(0, (float) ($allValues->max() ?? 0));
    $range = max($maximum - $minimum, 1);
    $count = max($periods->count(), 1);
    $x = fn (int $index) => $left + ($count === 1 ? $plotWidth / 2 : ($index / ($count - 1)) * $plotWidth);
    $y = fn (float $value) => $top + (($maximum - $value) / $range) * $plotHeight;
    $zeroY = $y(0);
    $formatAxis = function (float $value) use ($unit): string {
        if ($unit === '%') return number_format($value, 1).'%';
        return abs($value) >= 100000000
            ? number_format($value / 100000000, 1).'億'
            : number_format($value / 10000).'万';
    };
@endphp

<div class="finance-chart">
    <div class="finance-chart__legend">
        @foreach ($series as $item)
            <span><i style="background:{{ $item['color'] }}"></i>{{ $item['label'] }}</span>
        @endforeach
    </div>
    <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $title }}">
        @foreach ([0, .25, .5, .75, 1] as $step)
            @php($gridY = $top + $step * $plotHeight)
            @php($gridValue = $maximum - $step * $range)
            <line x1="{{ $left }}" y1="{{ $gridY }}" x2="{{ $width - $right }}" y2="{{ $gridY }}" stroke="#dfe6e9" stroke-width="1"/>
            <text x="{{ $left - 8 }}" y="{{ $gridY + 4 }}" text-anchor="end">{{ $formatAxis($gridValue) }}</text>
        @endforeach
        @if ($minimum < 0)
            <line x1="{{ $left }}" y1="{{ $zeroY }}" x2="{{ $width - $right }}" y2="{{ $zeroY }}" stroke="#9aa9af" stroke-width="1.5"/>
        @endif
        @foreach ($series as $item)
            @php($points = collect($item['values'])->map(fn ($value, $index) => $x($index).','.$y((float) $value))->join(' '))
            <polyline points="{{ $points }}" fill="none" stroke="{{ $item['color'] }}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            @foreach ($item['values'] as $index => $value)
                <circle cx="{{ $x($index) }}" cy="{{ $y((float) $value) }}" r="3.5" fill="{{ $item['color'] }}">
                    <title>{{ $periods[$index]->period_number }}期 {{ $item['label'] }}：{{ $unit === '%' ? number_format($value, 2).'%' : number_format($value).'円' }}</title>
                </circle>
            @endforeach
        @endforeach
        @foreach ($periods as $index => $period)
            @if ($index === 0 || $index === $periods->count() - 1 || $index % 4 === 0)
                <text x="{{ $x($index) }}" y="{{ $height - 14 }}" text-anchor="middle">{{ $period->period_number }}期</text>
            @endif
        @endforeach
    </svg>
</div>
