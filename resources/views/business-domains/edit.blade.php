@extends('layouts.app', ['title' => $domain->name.'を編集 - '.$organization->name])

@section('content')
<section class="stack">
    <div class="page-header"><div class="meta">BUSINESS DOMAIN / EDIT / REVISION {{ $domain->version }}</div><h1>{{ $domain->name }}</h1><p>保存すると新しいRevisionとして、変更理由と全体Snapshotが残ります。</p></div>
    <form method="POST" action="{{ route('business-domains.update', $domain) }}" class="stack">
        @csrf @method('PUT')
        @include('business-domains.partials.form', ['updating' => true])
    </form>
</section>
@endsection
