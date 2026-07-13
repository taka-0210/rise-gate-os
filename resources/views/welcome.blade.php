@extends('layouts.app', ['title' => 'Rise Gate OS'])

@section('content')
    <section class="panel stack">
        <div>
            <div class="meta">Company Operating System</div>
            <h1>Rise Gate OS</h1>
            <p>
                改善を、文化に。Rise Gate OS は、案件を管理するだけのシステムではありません。
                Project を中心に、改善、文書、判断、進捗を蓄積し、知識として育てるための土台です。
            </p>
        </div>

        <div class="grid">
            <div class="card">
                <h2>Project</h2>
                <p>社内メンバーとお客様が、改善プロジェクトを共有する場所。</p>
            </div>
            <div class="card">
                <h2>Improvement</h2>
                <p>現場で生まれた改善を、会社の資産として蓄積する。</p>
            </div>
            <div class="card">
                <h2>Workspace</h2>
                <p>Phase 1-2では、Current Workspaceを持つ土台を作ります。</p>
            </div>
        </div>

        <div class="actions">
            @auth
                <a class="button" href="{{ route('dashboard') }}">Dashboard</a>
                <a class="button secondary" href="{{ route('workspaces.index') }}">Workspaces</a>
            @else
                <a class="button" href="{{ route('register') }}">Start</a>
                <a class="button secondary" href="{{ route('login') }}">Login</a>
            @endauth
        </div>
    </section>
@endsection
