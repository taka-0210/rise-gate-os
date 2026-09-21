@extends('layouts.app', ['title' => $domain->name.' - '.$organization->name])

@section('content')
@php
    $axes = [
        'what_summary' => ['WHAT', '何を届けるか'],
        'who_summary' => ['WHO', '誰に届けるか'],
        'value_proposition' => ['VALUE', 'どんな価値を届けるか'],
        'geographic_scope_summary' => ['WHERE', 'どこで届けるか'],
        'market_position_summary' => ['POSITION', 'どの位置づけで届けるか'],
    ];
    $kindLabels = [
        'product' => '商品', 'service' => 'サービス', 'brand' => 'ブランド', 'location' => '拠点',
        'channel' => 'チャネル', 'customer_segment' => '顧客層', 'market' => '市場', 'other' => 'その他',
    ];
    $axisLabels = ['what' => 'WHAT', 'who' => 'WHO', 'value' => 'VALUE', 'where' => 'WHERE', 'position' => 'POSITION'];
    $hasAxes = collect(array_keys($axes))->contains(fn ($field) => filled($domain->{$field}));
    $direction = $domain->direction ? (\App\Models\BusinessDomain::DIRECTIONS[$domain->direction] ?? null) : null;
@endphp
<x-company-context.page>
    @if(session('status'))<div class="notice company-context-print-hidden">{{ session('status') }}</div>@endif

    <x-company-context.title
        :company="$organization->name"
        :title="$domain->name"
        :status="$domain->status === 'archived' ? '保管済み' : null"
    >
        <x-slot:actions>
            <x-company-context.action-links>
                <a href="{{ route('business-domains.index', ['status' => $domain->status]) }}">一覧へ</a>
                @if($canEdit)
                    <a href="{{ route('business-domains.manage.show', $domain) }}">管理</a>
                    @if($domain->status === 'active')<a class="button" href="{{ route('business-domains.edit', $domain) }}">内容を編集</a>@endif
                @endif
            </x-company-context.action-links>
        </x-slot:actions>
    </x-company-context.title>

    @if($domain->status === 'archived')
        <div class="company-context-archive-notice">この事業領域は保管済みです。以下は保管時点の現在値です。</div>
    @endif

    @if(filled($domain->description))
        <x-company-context.statement>{{ $domain->description }}</x-company-context.statement>
    @endif

    @if($hasAxes)
        <x-company-context.section eyebrow="BUSINESS OUTLINE" title="事業の輪郭">
            <div class="company-context-axis-grid">
                @foreach($axes as $field => [$code, $label])
                    @if(filled($domain->{$field}))
                        <article class="company-context-axis">
                            <div class="company-context-axis__label">{{ $code }} / {{ $label }}</div>
                            <p>{{ $domain->{$field} }}</p>
                        </article>
                    @endif
                @endforeach
            </div>
        </x-company-context.section>
    @endif

    @if(filled($domain->self_recognized_strengths))
        <x-company-context.highlight label="SELF-RECOGNIZED STRENGTHS" title="自社認識の強み">
            {{ $domain->self_recognized_strengths }}
        </x-company-context.highlight>
    @endif

    @if($direction || filled($domain->direction_memo))
        <x-company-context.section eyebrow="DIRECTION" title="今後の方向性">
            <x-company-context.direction
                :label="$direction['label'] ?? '未設定'"
                :description="$direction['description'] ?? null"
                :memo="$domain->direction_memo"
            />
        </x-company-context.section>
    @endif

    @if($domain->items->isNotEmpty())
        <x-company-context.section eyebrow="DETAILS" title="この事業を形づくるもの">
            <div class="company-context-items">
                @foreach($domain->items as $item)
                    <article class="company-context-item">
                        <span class="company-context-item__kind">{{ $kindLabels[$item->kind] ?? $item->kind }}</span>
                        <h3>{{ $item->name }}</h3>
                        @if(filled($item->description))<p>{{ $item->description }}</p>@endif
                        @if($item->attributes->isNotEmpty())
                            <dl class="company-context-attributes">
                                @foreach($item->attributes as $attribute)
                                    <div>
                                        <dt>{{ $axisLabels[$attribute->axis] ?? strtoupper($attribute->axis) }} / {{ $attribute->label }}</dt>
                                        <dd>{{ $attribute->value_text }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </article>
                @endforeach
            </div>
        </x-company-context.section>
    @endif
</x-company-context.page>
@endsection
