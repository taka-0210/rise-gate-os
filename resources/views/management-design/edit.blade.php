@extends('layouts.app', ['title' => $presentation['label'].'を編集 - '.$organization->name])

@section('content')
@include('management-design._styles')
@php
    $sectionValues = old('sections', $item?->sections?->map(fn ($section) => [
        'public_id' => $section->public_id,
        'title' => $section->title,
        'body' => $section->body,
        'explanation' => $section->explanation,
        'horizon' => $section->horizon,
    ])->values()->all() ?? []);
@endphp
<section class="mdc-shell stack">
    <header class="mdc-form__intro">
        <div><p class="mdc-kicker">{{ strtoupper($type) }} / OFFICIAL EDIT</p><h1>{{ $presentation['label'] }}</h1><p>確認後に［正本として保存］すると、現在のSection一式が新しいimmutable Revisionになります。</p></div>
        <a class="button secondary" href="{{ route('management-design.show', $type) }}">読む画面へ戻る</a>
    </header>
    @if($errors->any())<div class="panel" role="alert"><strong>保存できませんでした。</strong>@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</div>@endif
    <form method="POST" action="{{ route('management-design.update', $type) }}" class="mdc-form" data-mdc-form>
        @csrf @method('PUT')
        <input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
        <input type="hidden" name="expected_version" value="{{ $item?->version ?? 0 }}">
        <div class="field"><label for="mdc-statement">全体Statement</label><textarea id="mdc-statement" name="statement" maxlength="50000">{{ old('statement', $item?->statement) }}</textarea><small>会社の言葉を主役にする全体Statementです。空でも保存できます。</small></div>
        <div class="field"><label for="mdc-statement-explanation">{{ $presentation['statement_explanation_label'] }}</label><textarea id="mdc-statement-explanation" name="statement_explanation" maxlength="50000">{{ old('statement_explanation', $item?->statement_explanation) }}</textarea><small>{{ $presentation['statement_explanation_help'] }}</small></div>
        @if($type === 'vision')<div class="field"><label for="mdc-horizon">Horizon（任意）</label><input id="mdc-horizon" name="horizon" maxlength="255" value="{{ old('horizon', $item?->horizon) }}"><small>期限・KPI・進捗・自動失効には使用しません。</small></div>@endif
        <section class="stack" aria-labelledby="mdc-sections-heading">
            <div class="actions" style="justify-content:space-between"><div><p class="mdc-kicker">SECTIONS / 0..N</p><h2 id="mdc-sections-heading">Section</h2></div><button class="secondary" type="button" data-add-section>Sectionを追加</button></div>
            <div class="stack" data-sections>
                @foreach($sectionValues as $index => $section)
                    <fieldset class="mdc-form__section" data-section>
                        <div class="mdc-form__section-head"><legend>Section {{ $loop->iteration }}</legend><button class="danger-outline" type="button" data-remove-section>削除</button></div>
                        @if(filled($section['public_id'] ?? null))<input type="hidden" name="sections[{{ $index }}][public_id]" value="{{ $section['public_id'] }}">@endif
                        <div class="field"><label for="section-title-{{ $index }}">Section名</label><input id="section-title-{{ $index }}" name="sections[{{ $index }}][title]" maxlength="255" required value="{{ $section['title'] ?? '' }}"></div>
                        <div class="field"><label for="section-body-{{ $index }}">Section Statement</label><textarea id="section-body-{{ $index }}" name="sections[{{ $index }}][body]" maxlength="50000" required>{{ $section['body'] ?? '' }}</textarea></div>
                        <div class="field"><label for="section-explanation-{{ $index }}">{{ $presentation['section_explanation_label'] }}</label><textarea id="section-explanation-{{ $index }}" name="sections[{{ $index }}][explanation]" maxlength="50000">{{ $section['explanation'] ?? '' }}</textarea></div>
                        @if($type === 'vision')<div class="field"><label for="section-horizon-{{ $index }}">Section Horizon（任意）</label><input id="section-horizon-{{ $index }}" name="sections[{{ $index }}][horizon]" maxlength="255" value="{{ $section['horizon'] ?? '' }}"></div>@endif
                    </fieldset>
                @endforeach
            </div>
        </section>
        <div class="field"><label for="change-reason">変更理由（任意）</label><textarea id="change-reason" name="change_reason" rows="3" maxlength="2000">{{ old('change_reason') }}</textarea></div>
        <div class="mdc-actions"><button type="submit">正本として保存</button><a class="button secondary" href="{{ route('management-design.show', $type) }}">キャンセル</a></div>
    </form>
</section>
<template data-section-template>
    <fieldset class="mdc-form__section" data-section>
        <div class="mdc-form__section-head"><legend>New Section</legend><button class="danger-outline" type="button" data-remove-section>削除</button></div>
        <div class="field"><label>Section名<input name="sections[__INDEX__][title]" maxlength="255" required></label></div>
        <div class="field"><label>Section Statement<textarea name="sections[__INDEX__][body]" maxlength="50000" required></textarea></label></div>
        <div class="field"><label>{{ $presentation['section_explanation_label'] }}<textarea name="sections[__INDEX__][explanation]" maxlength="50000"></textarea></label></div>
        @if($type === 'vision')<div class="field"><label>Section Horizon（任意）<input name="sections[__INDEX__][horizon]" maxlength="255"></label></div>@endif
    </fieldset>
</template>
<script>
(() => {
    const form = document.querySelector('[data-mdc-form]');
    if (!form) return;
    const list = form.querySelector('[data-sections]');
    const template = document.querySelector('[data-section-template]');
    let next = {{ count($sectionValues) }};
    form.querySelector('[data-add-section]').addEventListener('click', () => {
        list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next++)));
        list.lastElementChild.querySelector('input, textarea')?.focus();
    });
    form.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-section]');
        if (button) button.closest('[data-section]').remove();
    });
})();
</script>
@endsection
