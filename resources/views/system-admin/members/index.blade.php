@extends('layouts.app', ['title' => 'System Admin - Company OS'])

@section('content')
    <section class="stack">
        <div>
            <h1>システム管理</h1>
            <p>Company OSのメンバーを登録し、専用Workspaceの作成または既存Workspaceへの所属を行います。</p>
        </div>

        @if (session('status'))
            <div class="panel">{{ session('status') }}</div>
        @endif

        <div class="panel stack">
            <h2>Staff追加</h2>
            <p>通常StaffのAccount作成は、対象Organizationの管理画面からInvitationを利用してください。</p>
            <p class="meta">System Adminから初期Passwordや恒久Passwordを設定することはできません。</p>
        </div>

        <div class="stack">
            <h2>登録済みメンバー</h2>
            <div class="grid">
                @foreach ($members as $member)
                    <article class="card stack">
                        <div>
                            <x-user-avatar :user='$member' :size='40' />
                            <h2>{{ $member->name }}</h2>
                            <div class="meta">{{ $member->email }}</div>
                        </div>
                        @if ($member->is_system_admin)<span class="badge">System Admin</span>@endif
                        <div class="meta">
                            @forelse ($member->workspaces as $workspace)
                                <div>{{ $workspace->organization->name }} / {{ $workspace->name }}（{{ $workspace->pivot->role }}）</div>
                            @empty
                                Workspace未所属
                            @endforelse
                        </div>
                        <div><a class="button secondary" href="{{ route('system-admin.members.edit', $member) }}">編集</a></div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

@endsection
