@extends('layouts.app', ['title' => $client->name.' - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div class="actions" style="justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="meta">Company</div>
                <h1>{{ $client->name }}</h1>
                <p>{{ $client->memo ?: 'メモはまだありません。' }}</p>
            </div>
            <a class="button secondary" href="{{ route('clients.index') }}">Back to Clients</a>
        </div>

        <div class="grid">
            <div class="card">
                <h2>Email</h2>
                <p>{{ $client->email ?: 'Not set' }}</p>
            </div>
            <div class="card">
                <h2>Phone</h2>
                <p>{{ $client->phone ?: 'Not set' }}</p>
            </div>
            <div class="card">
                <h2>Website</h2>
                <p>
                    @if ($client->website)
                        <a href="{{ $client->website }}" target="_blank" rel="noreferrer">{{ $client->website }}</a>
                    @else
                        Not set
                    @endif
                </p>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h2>Kana</h2>
                <p>{{ $client->kana ?: 'Not set' }}</p>
            </div>
            <div class="card">
                <h2>Postal Code</h2>
                <p>{{ $client->postal_code ?: 'Not set' }}</p>
            </div>
            <div class="card">
                <h2>Address</h2>
                <p>{{ $client->address ?: 'Not set' }}</p>
            </div>
        </div>

        <div class="panel stack">
            <div class="actions" style="justify-content: space-between; align-items: flex-start;">
                <div>
                    <h2>Project Link</h2>
                    <p>ProjectはCompanyから生まれる改善の単位です。現在このCompanyに紐付くProjectは {{ $projectsCount }} 件です。</p>
                </div>
                <a class="button" href="{{ route('projects.create', ['client_id' => $client->id]) }}">Create Project</a>
            </div>
            <p class="meta">Client配下のProject一覧はPhase 1-5では作り込みません。Project一覧と詳細で関連を確認します。</p>
        </div>
    </section>
@endsection
