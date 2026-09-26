@extends('layouts.app')
@section('title','Capture Inbox')
@section('content')
<div class="page-heading"><div><p class="eyebrow">CAPTURE INBOX</p><h1>預けたこと</h1></div><a class="button" href="{{ route('captures.create') }}">Quick Capture</a></div>
<nav class="tabs"><a href="{{ route('captures.index',['box'=>'received','state'=>$state]) }}">自分宛</a><a href="{{ route('captures.index',['box'=>'created','state'=>$state]) }}">自分が作成</a></nav><nav class="tabs"><a href="{{ route('captures.index',['box'=>$box,'state'=>'open']) }}">未整理</a><a href="{{ route('captures.index',['box'=>$box,'state'=>'closed']) }}">整理終了</a><a href="{{ route('captures.index',['box'=>$box,'state'=>'converted']) }}">Action化済み</a></nav>
<div class="grid">@forelse($captures as $capture)<article class="card"><p class="eyebrow">{{ $capture->type }}</p><h2><a href="{{ route('captures.show',$capture) }}">{{ \Illuminate\Support\Str::limit($capture->body,60) }}</a></h2><p>{{ $capture->creator->name }} → {{ $capture->recipient->name }}</p></article>@empty<p>該当するCaptureはありません。</p>@endforelse</div>
{{ $captures->links() }}
@endsection
