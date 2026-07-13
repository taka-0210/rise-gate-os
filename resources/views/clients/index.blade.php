@extends('layouts.app', ['title' => 'Clients - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div class="actions" style="justify-content: space-between; align-items: flex-start;">
            <div>
                <h1>Clients</h1>
                <p>Clients are Companies. Projectは会社から生まれます。社内Projectの場合はClientなしでも扱えます。</p>
            </div>
            <a class="button" href="{{ route('clients.create') }}">Create Client</a>
        </div>

        @if ($clients->isEmpty())
            <div class="panel stack">
                <h2>まだClientがありません</h2>
                <p>最初のCompanyを登録して、Projectの親となる土台を作りましょう。</p>
                <div class="actions">
                    <a class="button" href="{{ route('clients.create') }}">Create first Client</a>
                </div>
            </div>
        @else
            <div class="grid">
                @foreach ($clients as $client)
                    <article class="card stack">
                        <div>
                            <div class="meta">Company / Projects: {{ $client->projects_count }}</div>
                            <h2><a href="{{ route('clients.show', $client) }}">{{ $client->name }}</a></h2>
                            @if ($client->address)
                                <p>{{ $client->address }}</p>
                            @else
                                <p>住所は未登録です。</p>
                            @endif
                        </div>
                        <div class="meta">
                            {{ $client->email ?: 'Email not set' }}
                        </div>
                    </article>
                @endforeach
            </div>

            {{ $clients->links() }}
        @endif
    </section>
@endsection
