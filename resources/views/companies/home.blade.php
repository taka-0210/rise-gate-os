@extends('layouts.app', ['title' => $company->name.' - Company OS'])

@section('content')
    <section class="stack">
        @if (session('status'))
            <div class="panel">{{ session('status') }}</div>
        @endif

        @if (session('owner_onboarding_completed'))
            <div class="panel stack">
                <div class="meta">NEXT STEP / OPTIONAL</div>
                <h2>Staffを迎える</h2>
                <p>今はSkipしてCompany OSを使い始められます。必要なときにOrganization設定からStaff Invitationを送れます。</p>
                @if ($canManageOrganization ?? false)
                    <div><a class="button" href="{{ route('organization-management.index') }}">Staff Invitationへ</a></div>
                @endif
            </div>
        @endif
        <div class="page-header">
            <div>
                <div class="meta">Company OS / COMPANY HOME</div>
                <h1>{{ $company->name }}</h1>
                <p>会社の方向、数字、Workspaceをつなぐ入口です。</p>
            </div>
            <a class="button" href="{{ route('workspaces.create') }}">Workspaceを作成</a>
        </div>

        <div class="grid">
            <a class="card" href="{{ route('action-executions.today') }}">
                <div class="meta">TODAY / CONTINUOUS EXECUTION</div><h2>今日のAction</h2>
                <p>今日実施すること、期限、確認待ちを一つの流れで確認します。</p>
            </a>
            <a class="card" href="{{ route('project-execution.index') }}">
                <div class="meta">PROJECT / ACTION</div>
                <h2>Project and Action</h2>
                <p>{{ $executionProjectCount }} active execution Project(s)</p>
            </a>
            <a class="card" href="{{ route('business-domains.index') }}">
                <div class="meta">BUSINESS DOMAIN / OPTIONAL</div>
                <h2>事業領域</h2>
                <p>現在の事業領域 {{ $businessDomainCount }}件。何を、誰に、どんな価値として届けるかを会社の共通情報として整理します。</p>
                @if ($canEditBusinessDomains)
                    <span class="badge">編集可能</span>
                @endif
            </a>
            <a class="card" href="{{ route('company-observations.index') }}">
                <div class="meta">OBSERVATION FIRST</div>
                <h2>気付き・観察</h2>
                <p>Observation {{ $observationCount }}件 / 確認待ち {{ $unreviewedObservationCount }}件</p>
            </a>
            @if (($canViewCompanyFinance ?? false) && ($canViewCompanyDebt ?? false))
                <a class="card" href="{{ route('company-finance.index') }}">
                    <div class="meta">FINANCE</div><h2>経営数値</h2><p>確定実績 {{ $financialPeriodCount }}期分</p>
                </a>
            @endif
            @if ($canManageCompanyMembers ?? false)
                <a class="card" href="{{ route('company-members.index') }}">
                    <div class="meta">MEMBERS</div><h2>所属ユーザー・権限</h2><p>会社とWorkspaceのアクセスを管理</p>
                </a>
            @endif
            @if ($canManageOrganization ?? false)
                <a class="card" href="{{ route('organization-management.index') }}">
                    <div class="meta">ORGANIZATION</div><h2>Organization設定</h2><p>Organization Role・Position・Groupを管理</p>
                </a>
            @endif
            <div class="card"><div class="meta">DIRECTION</div><h2>経営指針</h2><p>理念・未来・方針・計画（今後実装）</p></div>
            @if ($canViewCompanyDebt ?? false)
                <a class="card" href="{{ route('company-loans.index') }}"><div class="meta">DEBT / FUNDING</div><h2>借入・資金計画</h2><p>借入残高 {{ number_format($loanBalance) }}円</p></a>
            @endif
            <div class="card">
                <div class="meta">INSURANCE</div>
                <h2>保険</h2>
                <p>契約内容・保険料・更新時期を管理（今後実装）</p>
            </div>
            @if ($canViewCompanyFinance ?? false)
                <a class="card" href="{{ route('company-finance.repayment-capacity.index') }}">
                    <div class="meta">REPAYMENT CAPACITY</div>
                    <h2>減価償却・返済余力</h2>
                    <p>利益・減価償却費・元本返済額を年度別に確認</p>
                </a>
            @endif
        </div>

        <div class="panel stack">
            <div class="actions" style="justify-content:space-between;"><div><div class="meta">SHARED</div><h2>共有Workspace</h2></div><a href="{{ route('workspaces.index') }}">すべて表示</a></div>
            <div class="grid">
            <a class="card" href="{{ route('action-executions.today') }}">
                <div class="meta">TODAY / CONTINUOUS EXECUTION</div><h2>今日のAction</h2>
                <p>今日実施すること、期限、確認待ちを一つの流れで確認します。</p>
            </a>
                @forelse ($sharedWorkspaces as $workspace)
                    <article class="card"><h3>{{ $workspace->name }}</h3><p>{{ $workspace->projects_count }} Project / {{ $workspace->improvements_count }} 改善</p><form method="POST" action="{{ route('workspaces.switch', $workspace) }}">@csrf<button type="submit">開く</button></form></article>
                @empty
                    <p class="meta">参加中の共有Workspaceはありません。</p>
                @endforelse
            </div>
        </div>

        @if ($personalWorkspaceCreationEnabled || $personalWorkspaces->isNotEmpty())
        <div class="panel stack">
            <div><div class="meta">PERSONAL</div><h2>個人Workspace</h2></div>
            <div class="grid">
            <a class="card" href="{{ route('action-executions.today') }}">
                <div class="meta">TODAY / CONTINUOUS EXECUTION</div><h2>今日のAction</h2>
                <p>今日実施すること、期限、確認待ちを一つの流れで確認します。</p>
            </a>
                @forelse ($personalWorkspaces as $workspace)
                    <article class="card"><h3>{{ $workspace->name }}</h3><p>会社が所有する個人用の仕事場</p><form method="POST" action="{{ route('workspaces.switch', $workspace) }}">@csrf<button type="submit">開く</button></form></article>
                @empty
                    <p class="meta">個人Workspaceはまだありません。</p>
                @endforelse
            </div>
        </div>
        @endif
    </section>
@endsection
