@extends('layouts.app', ['title' => '年度経営方針 - '.$organization->name])

@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <header class="amp-header">
        <div><p class="amp-kicker">ANNUAL MANAGEMENT POLICY</p><h1>年度経営方針</h1><p class="amp-lead">期間ごとの正式な経営方針を読みます。作成中の内容は、承認されるまで正式版にはなりません。</p></div>
    </header>
    @if($canManage)
        <details class="panel"><summary><strong>会社の期間を登録</strong></summary>
            <form method="POST" action="{{ route('annual-management-policy.periods.store') }}" class="amp-form" style="margin-top:18px">@csrf
                <input type="hidden" name="request_id" value="{{ $requestId }}">
                <div class="field"><label for="period-name">期間名</label><input id="period-name" name="name" required maxlength="120" placeholder="2027年度"></div>
                <div class="amp-row"><div class="field"><label for="period-start">開始日</label><input id="period-start" type="date" name="starts_on" required></div><div class="field"><label for="period-end">終了日</label><input id="period-end" type="date" name="ends_on" required></div></div>
                <div><button type="submit">期間を登録</button></div>
            </form>
        </details>
    @endif
    @if($errors->any())<div class="panel" role="alert"><strong>入力を確認してください。</strong>@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</div>@endif
    <div class="amp-periods">
        @forelse($periods as $period)
            @php
                $policy = $period->annualPolicy;
                $canApproved = $policy && $access->canViewApproved(request()->user(), $policy);
                $canDraft = $policy && $access->canViewDraft(request()->user(), $policy);
                $visible = $policy && ($canApproved || $canDraft || $canManage);
            @endphp
            <article class="amp-period">
                <div class="stack" style="gap:8px">
                    <div class="amp-period__meta">
                        @if($period->id === $currentPeriodId)<span class="amp-status">今期</span>@elseif($period->starts_on->isFuture())<span class="amp-status">来期</span>@else<span class="amp-status">過去</span>@endif
                        @if($policy?->currentApprovedRevision)<span class="amp-status">正式版 Revision {{ $policy->currentApprovedRevision->revision_no }}</span>@elseif($visible)<span class="amp-status amp-status--draft">正式版は未承認</span>@endif
                    </div>
                    <h2>{{ $period->name }}</h2><div class="meta">{{ $period->starts_on->format('Y/m/d') }} – {{ $period->ends_on->format('Y/m/d') }} / 期間version {{ $period->version }}</div>
                </div>
                <div class="actions">
                    @if($visible)<a class="button secondary" href="{{ route('annual-management-policy.show', $policy) }}">開く</a>
                    @elseif($canManage)<form method="POST" action="{{ route('annual-management-policy.initialize', $period) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><button type="submit">方針の枠を作成</button></form>
                    @else<span class="meta">閲覧権限なし</span>@endif
                    @if($canManage)<details><summary>期間を訂正</summary><form method="POST" action="{{ route('annual-management-policy.periods.update',$period) }}" class="amp-form" style="margin-top:12px">@csrf @method('PUT')<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="expected_version" value="{{ $period->version }}"><label>期間名<input name="name" value="{{ $period->name }}" maxlength="120" required></label><label>開始日<input type="date" name="starts_on" value="{{ $period->starts_on->toDateString() }}" required></label><label>終了日<input type="date" name="ends_on" value="{{ $period->ends_on->toDateString() }}" required></label><label>訂正理由（任意）<textarea name="change_reason" maxlength="2000"></textarea></label><button type="submit">version付きで訂正</button></form></details>@endif
                </div>
            </article>
        @empty
            <div class="amp-empty">会社の期間はまだ登録されていません。</div>
        @endforelse
    </div>
</section>
@endsection
