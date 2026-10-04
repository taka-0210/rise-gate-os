@extends('layouts.app', ['title' => '年度経営方針 - '.$organization->name])

@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <header class="amp-header">
        <div><p class="amp-kicker">ANNUAL MANAGEMENT POLICY</p><h1>年度経営方針</h1><p class="amp-lead">現在有効な方針・計画中の次期方針・過年度の方針を確認できます。</p></div>
    </header>
    @if($canManage)
        <details class="panel"><summary><strong>会社の期間を登録</strong></summary>
            <form method="POST" action="{{ route('annual-management-policy.periods.store') }}" class="amp-form" style="margin-top:18px">@csrf
                <input type="hidden" name="request_id" value="{{ $requestId }}">
                <div class="amp-row"><div class="field"><label for="period-term">期数（任意）</label><input id="period-term" type="number" min="1" name="fiscal_term_number" value="{{ old('fiscal_term_number') }}" placeholder="例：23"><small>年度名とは別の会社固有の通算期数です。</small></div><div class="field"><label for="period-name">年度・期間名</label><input id="period-name" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="例：2026年度"></div></div>
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
                $state = $lifecycle->evaluate($period, $policy?->currentApprovedRevision !== null);
            @endphp
            <article class="amp-period">
                <div class="stack" style="gap:8px">
                    <div class="amp-period__meta">
                        <span class="amp-status amp-status--{{ $state['approval_status'] }} amp-status--{{ $state['effective_status'] }}">{{ $lifecycle->humanFacingLabel($state) }}</span>
                        @if($period->id === $currentPeriodId)<span class="amp-status">会社の現在期間</span>@endif
                        @if($policy?->currentApprovedRevision)<span class="meta">Revision {{ $policy->currentApprovedRevision->revision_no }}</span>@endif
                    </div>
                    <h2>{{ $period->display_label }}</h2><div class="meta">{{ $period->starts_on->format('Y/m/d') }} — {{ $period->ends_on->format('Y/m/d') }} / 期間version {{ $period->version }} / JST</div>
                </div>
                <div class="actions">
                    @if($visible)<a class="button secondary" href="{{ route('annual-management-policy.show', $policy) }}">開く</a>
                    @elseif($canManage)<form method="POST" action="{{ route('annual-management-policy.initialize', $period) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><button type="submit">方針の枠を作成</button></form>
                    @else<span class="meta">閲覧権限なし</span>@endif
                    @if($canManage)<details><summary>期間を訂正</summary><form method="POST" action="{{ route('annual-management-policy.periods.update',$period) }}" class="amp-form amp-form--compact">@csrf @method('PUT')<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="expected_version" value="{{ $period->version }}"><label>期数（任意）<input type="number" min="1" name="fiscal_term_number" value="{{ $period->fiscal_term_number }}"></label><label>年度・期間名<input name="name" value="{{ $period->name }}" maxlength="120" required></label><label>開始日<input type="date" name="starts_on" value="{{ $period->starts_on->toDateString() }}" required></label><label>終了日<input type="date" name="ends_on" value="{{ $period->ends_on->toDateString() }}" required></label><label>訂正理由（任意）<textarea name="change_reason" maxlength="2000"></textarea></label><button type="submit">version付きで訂正</button></form></details>@endif
                </div>
            </article>
        @empty
            <div class="amp-empty">会社の期間はまだ登録されていません。</div>
        @endforelse
    </div>
</section>
@endsection
