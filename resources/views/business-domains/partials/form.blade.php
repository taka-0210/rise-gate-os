@php
    $domainItems = $domain->exists
        ? $domain->items->map(fn ($item) => [
            'public_id' => $item->public_id,
            'kind' => $item->kind,
            'name' => $item->name,
            'description' => $item->description,
            'attributes' => $item->attributes->map(fn ($attribute) => [
                'public_id' => $attribute->public_id,
                'axis' => $attribute->axis,
                'label' => $attribute->label,
                'value_text' => $attribute->value_text,
            ])->all(),
        ])->all()
        : [];
    $items = old('items', $domainItems);
    $fieldLabels = [
        'description' => ['概要', 'この事業領域の全体像'],
        'what_summary' => ['WHAT / 何を', '提供する商品・サービス・体験'],
        'who_summary' => ['WHO / 誰に', '顧客・利用者・対象市場'],
        'value_proposition' => ['VALUE / どんな価値', '選ばれる理由・提供価値'],
        'geographic_scope_summary' => ['WHERE / どこで', '地域・拠点・チャネル'],
        'market_position_summary' => ['POSITION / 位置づけ', '市場での立ち位置'],
        'self_recognized_strengths' => ['自社認識の強み', 'この領域で自社が強みと考えていること'],
    ];
@endphp
<input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
@if ($updating)<input type="hidden" name="expected_version" value="{{ old('expected_version', $domain->version) }}">@endif

@if ($errors->any())
    <div class="panel" role="alert"><strong>入力内容を確認してください。</strong><ul>@foreach($errors->all() as $error)<li class="error">{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="notice">この内容は、{{ $organization->name }} のactive Staffに社内共有されます。個人情報や機密情報は入力しないでください。</div>
<div class="meta">同じ名称も別の事業領域として登録できます。自動統合は行いません。</div>
<div class="panel stack">
    <div class="field"><label for="domain-name">名称 <span class="error">必須</span></label><input id="domain-name" name="name" value="{{ old('name', $domain->name) }}" maxlength="255" required></div>
    @foreach ($fieldLabels as $field => [$label, $placeholder])
        <div class="field"><label for="domain-{{ $field }}">{{ $label }}</label><textarea id="domain-{{ $field }}" name="{{ $field }}" rows="4" maxlength="10000" placeholder="{{ $placeholder }}">{{ old($field, $domain->{$field}) }}</textarea></div>
    @endforeach
    <div class="domain-direction-fields">
        <div class="field">
            <label for="domain-direction">今後の方向性</label>
            <select id="domain-direction" name="direction" data-direction-select>
                <option value="">未設定</option>
                @foreach ($directions as $code => $direction)
                    <option value="{{ $code }}" @selected(old('direction', $domain->direction) === $code)>{{ $direction['label'] }}</option>
                @endforeach
            </select>
            <p class="meta" data-direction-description>会社として、この事業をこれからどうしたいかを選びます。</p>
        </div>
        <div class="field">
            <label for="domain-direction-memo">方向性メモ</label>
            <textarea id="domain-direction-memo" name="direction_memo" rows="4" maxlength="10000" placeholder="方向性を選んだ背景や、これから目指したい状態">{{ old('direction_memo', $domain->direction_memo) }}</textarea>
        </div>
    </div>
</div>

<div class="panel stack">
    <div class="actions" style="justify-content:space-between;"><div><h2>明細</h2><p class="meta">商品、サービス、ブランド、拠点、チャネル、顧客層などを任意で追加できます。</p></div><button class="secondary" type="button" data-add-item>＋ 明細を追加</button></div>
    <div class="stack" data-items>
        @foreach ($items as $itemIndex => $item)
            <article class="card domain-item" data-item data-index="{{ $itemIndex }}">
                <input type="hidden" name="items[{{ $itemIndex }}][public_id]" value="{{ $item['public_id'] ?? '' }}">
                <div class="actions" style="justify-content:space-between;"><strong>明細</strong><button class="danger-outline" type="button" data-remove-item>明細を外す</button></div>
                <div class="domain-two-columns">
                    <div class="field"><label>種類</label><select name="items[{{ $itemIndex }}][kind]" required>@foreach($itemKinds as $kind)<option value="{{ $kind }}" @selected(($item['kind'] ?? '') === $kind)>{{ $kind }}</option>@endforeach</select></div>
                    <div class="field"><label>名称</label><input name="items[{{ $itemIndex }}][name]" value="{{ $item['name'] ?? '' }}" maxlength="255" required></div>
                </div>
                <div class="field"><label>説明</label><textarea name="items[{{ $itemIndex }}][description]" rows="3" maxlength="10000">{{ $item['description'] ?? '' }}</textarea></div>
                <div class="stack" data-attributes>
                    @foreach (($item['attributes'] ?? []) as $attributeIndex => $attribute)
                        <div class="domain-attribute" data-attribute>
                            <input type="hidden" name="items[{{ $itemIndex }}][attributes][{{ $attributeIndex }}][public_id]" value="{{ $attribute['public_id'] ?? '' }}">
                            <select aria-label="観点" name="items[{{ $itemIndex }}][attributes][{{ $attributeIndex }}][axis]" required>@foreach($attributeAxes as $axis)<option value="{{ $axis }}" @selected(($attribute['axis'] ?? '') === $axis)>{{ strtoupper($axis) }}</option>@endforeach</select>
                            <input aria-label="属性名" name="items[{{ $itemIndex }}][attributes][{{ $attributeIndex }}][label]" value="{{ $attribute['label'] ?? '' }}" maxlength="255" placeholder="属性名" required>
                            <textarea aria-label="属性値" name="items[{{ $itemIndex }}][attributes][{{ $attributeIndex }}][value_text]" rows="2" maxlength="10000" placeholder="内容" required>{{ $attribute['value_text'] ?? '' }}</textarea>
                            <button class="secondary" type="button" data-remove-attribute>外す</button>
                        </div>
                    @endforeach
                </div>
                <button class="secondary" type="button" data-add-attribute>属性を追加</button>
            </article>
        @endforeach
    </div>
    <div class="domain-add-item-footer"><button class="secondary" type="button" data-add-item>＋ 明細を追加</button></div>
</div>

@if ($updating)
<div class="panel field"><label for="change-reason">変更理由 <span class="error">必須</span></label><textarea id="change-reason" name="change_reason" rows="3" maxlength="2000" required>{{ old('change_reason') }}</textarea></div>
@endif
<div class="actions"><button type="submit">{{ $updating ? '新しいRevisionとして保存' : '登録' }}</button><a class="button secondary" href="{{ $domain->exists ? route('business-domains.show', $domain) : route('business-domains.index') }}">キャンセル</a></div>

<style>
.domain-direction-fields{display:grid;grid-template-columns:minmax(220px,.7fr) minmax(0,1.3fr);gap:14px;padding-top:4px;border-top:1px solid var(--line)}.domain-direction-fields .meta{margin:0}.domain-two-columns{display:grid;grid-template-columns:180px minmax(0,1fr);gap:12px}.domain-item{display:grid;gap:14px;scroll-margin-top:20px}.domain-attribute{display:grid;grid-template-columns:130px minmax(150px,.7fr) minmax(220px,1.3fr) auto;gap:8px;align-items:start}.domain-attribute textarea{resize:vertical}.domain-add-item-footer{display:flex;justify-content:center;padding-top:2px}.domain-add-item-footer button{min-width:180px}@media(max-width:700px){.domain-direction-fields,.domain-two-columns,.domain-attribute{grid-template-columns:1fr}.domain-attribute button{justify-self:start}.domain-add-item-footer button{width:100%}}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const items = document.querySelector('[data-items]');
    const kinds = @json($itemKinds);
    const axes = @json($attributeAxes);
    const directions = @json($directions);
    const directionSelect = document.querySelector('[data-direction-select]');
    const directionDescription = document.querySelector('[data-direction-description]');
    const renderDirectionDescription = () => {
        directionDescription.textContent = directions[directionSelect.value]?.description
            || '会社として、この事業をこれからどうしたいかを選びます。';
    };
    directionSelect.addEventListener('change', renderDirectionDescription);
    renderDirectionDescription();
    let nextItem = Math.max({{ count($items) }}, ...Array.from(items.querySelectorAll('[data-item]')).map(item => Number(item.dataset.index) + 1));
    const optionHtml = values => values.map(value => `<option value="${value}">${value.toUpperCase()}</option>`).join('');
    const addAttribute = item => {
        const list = item.querySelector('[data-attributes]');
        const itemIndex = item.dataset.index;
        const attributeIndex = Number(item.dataset.nextAttribute || list.children.length);
        item.dataset.nextAttribute = attributeIndex + 1;
        const row = document.createElement('div'); row.className = 'domain-attribute'; row.dataset.attribute = '';
        row.innerHTML = `<select aria-label="観点" name="items[${itemIndex}][attributes][${attributeIndex}][axis]" required>${optionHtml(axes)}</select><input aria-label="属性名" name="items[${itemIndex}][attributes][${attributeIndex}][label]" maxlength="255" placeholder="属性名" required><textarea aria-label="属性値" name="items[${itemIndex}][attributes][${attributeIndex}][value_text]" rows="2" maxlength="10000" placeholder="内容" required></textarea><button class="secondary" type="button" data-remove-attribute>外す</button>`;
        list.append(row);
    };
    const prepare = (item, index) => { item.dataset.index = index; item.dataset.nextAttribute = item.querySelectorAll('[data-attribute]').length; item.querySelector('[data-add-attribute]').addEventListener('click', () => addAttribute(item)); };
    items.querySelectorAll('[data-item]').forEach((item, index) => prepare(item, Number(item.dataset.index || index)));
    const addItem = () => {
        const index = nextItem++; const item = document.createElement('article'); item.className = 'card domain-item'; item.dataset.item = '';
        item.innerHTML = `<div class="actions" style="justify-content:space-between"><strong>明細</strong><button class="danger-outline" type="button" data-remove-item>明細を外す</button></div><div class="domain-two-columns"><div class="field"><label>種類</label><select name="items[${index}][kind]" required>${optionHtml(kinds)}</select></div><div class="field"><label>名称</label><input name="items[${index}][name]" maxlength="255" required></div></div><div class="field"><label>説明</label><textarea name="items[${index}][description]" rows="3" maxlength="10000"></textarea></div><div class="stack" data-attributes></div><button class="secondary" type="button" data-add-attribute>属性を追加</button>`;
        items.append(item); prepare(item, index);
        requestAnimationFrame(() => {
            item.scrollIntoView({behavior: 'smooth', block: 'center'});
            item.querySelector('input[name$="[name]"]').focus({preventScroll: true});
        });
    };
    document.querySelectorAll('[data-add-item]').forEach(button => button.addEventListener('click', addItem));
    document.addEventListener('click', event => {
        if (event.target.matches('[data-remove-item]')
            && window.confirm('この明細を入力フォームから外します。保存すると保管されます。よろしいですか？')) {
            event.target.closest('[data-item]').remove();
        }
        if (event.target.matches('[data-remove-attribute]')) event.target.closest('[data-attribute]').remove();
    });
});
</script>
