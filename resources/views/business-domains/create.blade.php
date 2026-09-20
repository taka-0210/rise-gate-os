@extends('layouts.app', ['title' => '事業領域を追加 - '.$organization->name])

@section('content')
<section class="stack">
    <div class="page-header"><div class="meta">BUSINESS DOMAIN / NEW</div><h1>事業領域を追加</h1><p>名称だけが必須です。分かる範囲から記録してください。</p></div>
    <form method="POST" action="{{ route('business-domains.store') }}" class="stack">
        @csrf
        @include('business-domains.partials.form', ['updating' => false])
    </form>
</section>
@endsection
