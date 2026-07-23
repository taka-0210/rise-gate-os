@extends('layouts.app', ['title' => '会社を選択 - COMPANY OS'])

@section('content')
    <section class="stack">
        <div>
            <div class="meta">COMPANY OS</div>
            <h1>会社を選択</h1>
            <p>経営・仕事を確認する会社を選択してください。</p>
        </div>
        @if ($companies->isEmpty())
            <div class="panel"><p>所属している会社アカウントがありません。会社管理者からの招待が必要です。</p></div>
        @else
            <div class="grid">
                @foreach ($companies as $company)
                    <article class="card stack">
                        <div class="meta">会社アカウント</div>
                        <h2>{{ $company->name }}</h2>
                        <p>{{ $company->workspaces_count }} Workspace</p>
                        <form method="POST" action="{{ route('companies.switch', $company) }}">
                            @csrf
                            <button type="submit">この会社に入る</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
