@extends('layouts.app', ['title' => '事業領域 - '.$organization->name])

@section('content')
@php
    $tabParameters = fn (string $nextStatus): array => array_filter([
        'status' => $nextStatus,
        'q' => $search,
        'per_page' => request('per_page'),
    ], fn ($value) => $value !== null && $value !== '');
@endphp
<x-company-context.page wide class="company-context-directory">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif

    <section class="company-context-directory__hero">
        <div class="company-context-directory__visual" aria-hidden="true">
            <img src="{{ asset('images/company-os-brand-symbol.svg') }}" alt="" width="2880" height="1620">
        </div>
        <x-company-context.title title="事業領域">
            @if($canEdit)
                <x-slot:actions>
                    <x-company-context.action-links>
                        <a href="{{ route('business-domains.manage', $tabParameters($status)) }}">管理する</a>
                    </x-company-context.action-links>
                </x-slot:actions>
            @endif
        </x-company-context.title>

        <x-company-context.statement>
            会社が「何を、誰に、どんな価値として、どこで、どの位置づけで」届けるか。私たちの事業の現在地を共有します。
        </x-company-context.statement>
    </section>

    <nav class="company-context-tabs company-context-print-hidden" aria-label="事業領域の表示">
        <a href="{{ route('business-domains.index', $tabParameters('active')) }}" class="company-context-tab {{ $status === 'active' ? 'is-active' : '' }}" @if($status === 'active') aria-current="page" @endif>
            利用中 <span>{{ $statusCounts['active'] }}</span>
        </a>
        <a href="{{ route('business-domains.index', $tabParameters('archived')) }}" class="company-context-tab {{ $status === 'archived' ? 'is-active' : '' }}" @if($status === 'archived') aria-current="page" @endif>
            保管済み <span>{{ $statusCounts['archived'] }}</span>
        </a>
    </nav>

    <div class="company-context-list">
        @forelse($domains as $domain)
            <a class="company-context-card" href="{{ route('business-domains.show', $domain) }}">
                <div class="company-context-card__meta">
                    <span>{{ $domain->status === 'active' ? '利用中' : '保管済み' }}</span>
                    @if($domain->direction)<span>方向性：{{ \App\Models\BusinessDomain::directionLabel($domain->direction) }}</span>@endif
                    @if($domain->items_count > 0)<span>明細 {{ $domain->items_count }}件</span>@endif
                </div>
                <h2>{{ $domain->name }}</h2>
                @if($domain->description)<p>{{ \Illuminate\Support\Str::limit($domain->description, 220) }}</p>@endif
                <span class="company-context-card__arrow" aria-hidden="true">→</span>
            </a>
        @empty
            <div class="company-context-empty">
                <h2>事業領域の情報は準備中です</h2>
                <p>登録済みの情報がない場合も、Company OSのほかの機能はそのまま利用できます。</p>
                @if($canEdit && $status === 'active')
                    <div class="company-context-print-hidden"><a class="button" href="{{ route('business-domains.create') }}">事業領域を追加</a></div>
                @endif
            </div>
        @endforelse
    </div>
    <div class="company-context-print-hidden">{{ $domains->links() }}</div>
</x-company-context.page>
@endsection
