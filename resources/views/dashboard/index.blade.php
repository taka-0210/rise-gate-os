@extends('layouts.app', ['title' => 'Dashboard - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div>
            <h1>{{ $currentWorkspace->name }}</h1>
            <p>Current Workspace is set. Phase 1-2では、このWorkspaceを基準に今後の画面が動作する土台までを実装しています。</p>
        </div>

        <div class="grid">
            <div class="card">
                <h2>Organization</h2>
                <p>{{ $currentWorkspace->organization->name }}</p>
            </div>
            <div class="card">
                <h2>Your Role</h2>
                <p>{{ $currentWorkspaceRole }}</p>
            </div>
            <div class="card">
                <h2>Next</h2>
                <p>まずProjectsから業務ドメインを開始します。Clients、Tasks、Improvements、Documentsはまだ未実装です。</p><div class="actions"><a class="button" href="{{ route('projects.index') }}">Open Projects</a></div>
            </div>
        </div>
    </section>
@endsection

