@extends('layouts.app', ['title' => 'Create Client - Rise Gate OS'])

@section('content')
    <section class="panel stack">
        <div>
            <h1>Create Client</h1>
            <p>Clientは担当者ではなく会社そのものです。ContactsやUsersとの紐付けは今後のPhaseで扱います。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('clients.store') }}">
            @csrf

            <div class="field">
                <label for="name">Company name</label>
                <input id="name" name="name" value="{{ old('name') }}" required autofocus>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="kana">Kana</label>
                <input id="kana" name="kana" value="{{ old('kana') }}">
                @error('kana') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid">
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}">
                    @error('email') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="phone">Phone</label>
                    <input id="phone" name="phone" value="{{ old('phone') }}">
                    @error('phone') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="website">Website</label>
                    <input id="website" name="website" type="url" value="{{ old('website') }}" placeholder="https://example.com">
                    @error('website') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="postal_code">Postal code</label>
                    <input id="postal_code" name="postal_code" value="{{ old('postal_code') }}">
                    @error('postal_code') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field" style="grid-column: span 2;">
                    <label for="address">Address</label>
                    <input id="address" name="address" value="{{ old('address') }}">
                    @error('address') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <label for="memo">Memo</label>
                <textarea id="memo" name="memo" rows="5">{{ old('memo') }}</textarea>
                @error('memo') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button type="submit">Create Client</button>
                <a href="{{ route('clients.index') }}">Cancel</a>
            </div>
        </form>
    </section>
@endsection
