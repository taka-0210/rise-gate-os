@extends('layouts.app', ['title' => 'Owner Onboarding - Company OS'])

@section('content')
<section class="stack owner-onboarding-admin">
    <div><div class="meta">SYSTEM ADMIN / OWNER ONBOARDING</div><h1>新しい会社の開始承認</h1><p>承認先本人がAccount・会社・標準Workspaceを開始します。System Adminは会社へ自動所属しません。</p></div>
    @if (session('status')) <div class="notice">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="error">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div> @endif

    @unless(config('owner_onboarding.legal_documents_published'))
        <div class="panel"><strong>Release Gate:</strong> 正式な利用規約・Privacyが未公開のため、顧客向け開始案内は発行できません。</div>
    @endunless

    <div class="panel stack">
        <h2>開始案内を発行</h2>
        <form method="POST" action="{{ route('system-admin.owner-onboardings.store') }}" class="stack">
            @csrf
            <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="field"><label for="organization_name">Organization名</label><input id="organization_name" name="organization_name" value="{{ old('organization_name') }}" maxlength="255" required></div>
            <div class="field"><label for="email">最初のOwner Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required></div>
            <div class="field"><label for="duplicate_decision">同名候補の確認</label><select id="duplicate_decision" name="duplicate_decision"><option value="no_match">同じ会社の候補なし</option><option value="distinct_company" @selected(old('duplicate_decision') === 'distinct_company')>同名候補はあるが別会社</option></select></div>
            <div class="field"><label for="distinct_company_reason">別会社と判断した理由</label><input id="distinct_company_reason" name="distinct_company_reason" value="{{ old('distinct_company_reason') }}" maxlength="500"></div>
            <div><button type="submit" @disabled(! config('owner_onboarding.legal_documents_published'))>開始案内を発行</button></div>
        </form>
        <p class="meta">既存Organizationへの参加は、そのOrganizationのStaff Invitationを利用してください。同じ開始案件は新規発行せず、下の再送を利用します。</p>
    </div>

    <div class="panel stack">
        <h2>既存Organization候補</h2>
        <div class="grid">@forelse($organizations as $organization)<div class="card"><strong>{{ $organization->name }}</strong><div class="meta">{{ $organization->public_id }}</div></div>@empty<p class="meta">既存Organizationはありません。</p>@endforelse</div>
    </div>

    <div class="stack">
        <h2>開始案件</h2>
        @forelse($onboardings as $item)
            <article class="card stack">
                <div><strong>{{ $item->organization_name }}</strong><div class="meta">開始管理番号 {{ $item->public_id }} / {{ $item->normalized_email }}</div></div>
                <div class="actions"><span class="badge">{{ $item->status }}</span><span class="meta">generation {{ $item->token_generation }} / {{ $item->delivery_status }}</span></div>
                @if($item->distinct_company_reason)<p class="meta">別会社理由: {{ $item->distinct_company_reason }}</p>@endif
                @if($item->isIssued())
                    <div class="actions">
                        <form method="POST" action="{{ route('system-admin.owner-onboardings.resend', $item) }}">@csrf<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button type="submit" class="secondary">再送</button></form>
                        <form method="POST" action="{{ route('system-admin.owner-onboardings.revoke', $item) }}">@csrf @method('DELETE')<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button type="submit" class="danger">取消</button></form>
                    </div>
                @endif
            </article>
        @empty <p class="meta">開始案件はありません。</p> @endforelse
    </div>
</section>
@endsection
