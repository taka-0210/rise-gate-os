@extends('layouts.app', ['title' => 'Workspaces - System Admin'])

@section('content')
    <section class="stack">
        <div>
            <h1>Workspace管理</h1>
            <p>Rise Gate OSに登録されているすべてのWorkspaceを管理します。</p>
        </div>
        <div class="grid">
            @foreach ($workspaces as $workspace)
                <article class="card stack">
                    <div>
                        <h2>{{ $workspace->name }}</h2>
                        <div class="meta">{{ $workspace->organization->name }} / {{ $workspace->users_count }} members</div>
                        <div class="meta">契約者: {{ $workspace->owner?->name ?? '未設定' }} / {{ $workspace->billing_type }} / {{ $workspace->status }}</div>
                    </div>
                    <div><a class="button secondary" href="{{ route('system-admin.workspaces.edit', $workspace) }}">名称を編集</a></div>
                </article>
            @endforeach
        </div>
    </section>
@endsection
