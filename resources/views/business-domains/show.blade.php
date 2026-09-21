@extends('layouts.app', ['title' => $domain->name.' - '.$organization->name])

@section('content')
@php
    $axisDefinitions = [
        'what_summary' => ['code' => 'WHAT', 'label' => '何を届けるか'],
        'who_summary' => ['code' => 'WHO', 'label' => '誰に届けるか'],
        'value_proposition' => ['code' => 'VALUE', 'label' => 'どんな価値を届けるか'],
        'geographic_scope_summary' => ['code' => 'WHERE', 'label' => 'どこで届けるか'],
        'market_position_summary' => ['code' => 'POSITION', 'label' => 'どの位置づけで届けるか'],
    ];
    $axes = collect($axisDefinitions)
        ->filter(fn (array $definition, string $field) => filled($domain->{$field}))
        ->map(fn (array $definition, string $field) => [...$definition, 'value' => $domain->{$field}])
        ->values()
        ->all();
    $kindPresentation = [
        'product' => ['WHAT WE MAKE', '商品'],
        'service' => ['WHAT WE DO', 'サービス'],
        'brand' => ['OUR BRANDS', 'ブランド'],
        'location' => ['WHERE WE WORK', '拠点'],
        'channel' => ['HOW WE REACH', 'チャネル'],
        'customer_segment' => ['WHO WE SERVE', '顧客層'],
        'market' => ['WHERE WE COMPETE', '市場'],
        'other' => ['MORE ABOUT THIS BUSINESS', 'その他'],
    ];
    $axisLabels = ['what' => 'WHAT', 'who' => 'WHO', 'value' => 'VALUE', 'where' => 'WHERE', 'position' => 'POSITION'];
    $direction = $domain->direction ? (\App\Models\BusinessDomain::DIRECTIONS[$domain->direction] ?? null) : null;
@endphp
<x-company-context.page class="company-context-story">
    @if(session('status'))<div class="notice company-context-print-hidden">{{ session('status') }}</div>@endif

    <x-company-context.hero
        :company="$organization->name"
        :title="$domain->name"
        :description="$domain->description"
        :value="$domain->value_proposition"
        :position="$domain->market_position_summary"
        :status="$domain->status === 'archived' ? '保管済み' : null"
    >
        <x-slot:actions>
            <x-company-context.tools>
                <a href="{{ route('business-domains.index', ['status' => $domain->status]) }}">事業領域の一覧</a>
                @if($canEdit)
                    <a href="{{ route('business-domains.manage.show', $domain) }}">管理・履歴</a>
                    @if($domain->status === 'active')<a href="{{ route('business-domains.edit', $domain) }}">内容を編集</a>@endif
                @endif
            </x-company-context.tools>
        </x-slot:actions>
    </x-company-context.hero>

    @if($domain->status === 'archived')
        <div class="company-context-archive-notice" data-context-reveal>この事業領域は保管済みです。以下は保管時点の現在値です。</div>
    @endif

    <div class="company-context-story__body" id="business-story">
        @if(filled($domain->value_proposition))
            <section class="company-context-key-message" id="business-value" data-context-reveal>
                <p class="company-context-story__eyebrow">THE VALUE WE CREATE</p>
                <p class="company-context-key-message__text">{{ $domain->value_proposition }}</p>
            </section>
        @endif

        @if(count($axes) > 0)
            <section class="company-context-story-section company-context-story-section--outline" data-context-reveal>
                <header class="company-context-story-heading">
                    <p class="company-context-story__eyebrow">BUSINESS OUTLINE</p>
                    <h2>事業の輪郭</h2>
                    <p>ひとつの事業を、5つの視点から捉えます。</p>
                </header>
                <x-company-context.perspectives :axes="$axes" />
            </section>
        @endif

        @if(filled($domain->self_recognized_strengths))
            <section class="company-context-strength" data-context-reveal>
                <p class="company-context-story__eyebrow">SELF-RECOGNIZED STRENGTHS</p>
                <h2>自社認識の強み</h2>
                <p class="company-context-strength__statement">{{ $domain->self_recognized_strengths }}</p>
            </section>
        @endif

        @if($domain->items->isNotEmpty())
            <section class="company-context-story-section company-context-story-section--details" data-context-reveal>
                <header class="company-context-story-heading">
                    <p class="company-context-story__eyebrow">THE BUSINESS, IN PRACTICE</p>
                    <h2>この事業を形づくるもの</h2>
                </header>
                <div class="company-context-items">
                    @foreach($domain->items as $item)
                        @php($presentation = $kindPresentation[$item->kind] ?? [$item->kind, $item->kind])
                        <article class="company-context-item" data-context-reveal>
                            <div class="company-context-item__index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                            <div class="company-context-item__content">
                                <p class="company-context-item__theme">{{ $presentation[0] }}</p>
                                <p class="company-context-item__kind">{{ $presentation[1] }}</p>
                                <h3>{{ $item->name }}</h3>
                                @if(filled($item->description))<p class="company-context-item__description">{{ $item->description }}</p>@endif
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
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if($direction || filled($domain->direction_memo))
            <section class="company-context-next" data-context-reveal>
                <p class="company-context-story__eyebrow">WHERE WE GO NEXT</p>
                <p class="company-context-next__label">{{ $direction['label'] ?? '未設定' }}</p>
                @if($direction['description'] ?? null)<p class="company-context-next__description">{{ $direction['description'] }}</p>@endif
                @if(filled($domain->direction_memo))<p class="company-context-next__memo">{{ $domain->direction_memo }}</p>@endif
            </section>
        @endif
    </div>
</x-company-context.page>
@endsection
