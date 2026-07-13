@extends('layouts.app', ['title' => 'Workspaces - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div>
            <h1>Workspaceを選択</h1>
            <p>今後の画面は Current Workspace を基準に動作します。</p>
        </div>

        @if ($workspaces->isEmpty())
            <div class="panel">
                <p>参加中のWorkspaceがありません。管理者に招待を依頼してください。</p>
            </div>
        @else
            <div class="grid">
                @foreach ($workspaces as $workspace)
                    <article class="card stack">
                        <div>
                            <h2>{{ $workspace->name }}</h2>
                            <div class="meta">{{ $workspace->organization->name }} / {{ $workspace->pivot->role }}</div>
                        </div>
                        <form method="POST" action="{{ route('workspaces.switch', $workspace) }}">
                            @csrf
                            <button class="{{ $currentWorkspaceId === $workspace->id ? 'secondary' : '' }}" type="submit">
                                {{ $currentWorkspaceId === $workspace->id ? 'Current Workspace' : 'Select Workspace' }}
                            </button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
