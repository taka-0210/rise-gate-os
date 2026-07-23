@extends('layouts.app', ['title' => $company->name.' - COMPANY OS'])

@section('content')
    <section class="stack">
        <div class="page-header">
            <div>
                <div class="meta">COMPANY OS / COMPANY HOME</div>
                <h1>{{ $company->name }}</h1>
                <p>会社の方向、数字、Workspaceをつなぐ入口です。</p>
            </div>
            <a class="button" href="{{ route('workspaces.create') }}">Workspaceを作成</a>
        </div>

        <div class="grid">
            @if ($canViewCompanyFinance ?? false)
                <a class="card" href="{{ route('company-finance.index') }}">
                    <div class="meta">FINANCE</div><h2>経営数値</h2><p>確定実績 {{ $financialPeriodCount }}期分</p>
                </a>
            @endif
            @if ($canManageCompanyMembers ?? false)
                <a class="card" href="{{ route('company-members.index') }}">
                    <div class="meta">MEMBERS</div><h2>所属ユーザー・権限</h2><p>会社とWorkspaceのアクセスを管理</p>
                </a>
            @endif
            <div class="card"><div class="meta">DIRECTION</div><h2>経営指針</h2><p>理念・未来・方針・計画（今後実装）</p></div>
            @if ($canViewCompanyDebt ?? false)
                <a class="card" href="{{ route('company-loans.index') }}"><div class="meta">DEBT / FUNDING</div><h2>借入・資金計画</h2><p>借入残高 {{ number_format($loanBalance) }}円</p></a>
            @endif
        </div>

        <div class="panel stack">
            <div class="actions" style="justify-content:space-between;"><div><div class="meta">SHARED</div><h2>共有Workspace</h2></div><a href="{{ route('workspaces.index') }}">すべて表示</a></div>
            <div class="grid">
                @forelse ($sharedWorkspaces as $workspace)
                    <article class="card"><h3>{{ $workspace->name }}</h3><p>{{ $workspace->projects_count }} Project / {{ $workspace->improvements_count }} 改善</p><form method="POST" action="{{ route('workspaces.switch', $workspace) }}">@csrf<button type="submit">開く</button></form></article>
                @empty
                    <p class="meta">参加中の共有Workspaceはありません。</p>
                @endforelse
            </div>
        </div>

        <div class="panel stack">
            <div><div class="meta">PERSONAL</div><h2>個人Workspace</h2></div>
            <div class="grid">
                @forelse ($personalWorkspaces as $workspace)
                    <article class="card"><h3>{{ $workspace->name }}</h3><p>会社が所有する個人用の仕事場</p><form method="POST" action="{{ route('workspaces.switch', $workspace) }}">@csrf<button type="submit">開く</button></form></article>
                @empty
                    <p class="meta">個人Workspaceはまだありません。</p>
                @endforelse
            </div>
        </div>
    </section>
@endsection
