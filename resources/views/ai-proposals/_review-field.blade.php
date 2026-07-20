@php
    $review = $item->review;
    $mergeTargets = $proposal->items->where('entity_type', $item->entity_type)->where('id', '!=', $item->id);
@endphp
<div class="proposal-review-field proposal-review-field--{{ $item->entity_type }}">
    <div class="proposal-review-field__title"><span class="meta">{{ $label }}</span><strong>{{ $item->attributes['title'] ?? $item->target_public_id ?? '名称未設定' }}</strong></div>
    <div class="proposal-review-field__inputs">
        <label>対応
            <select name="reviews[{{ $item->id }}][action]" required>
                @foreach ($reviewActions as $value => $actionLabel)
                    <option value="{{ $value }}" @selected($review?->action === $value || (! $review && $value === 'keep'))>{{ $actionLabel }}</option>
                @endforeach
            </select>
        </label>
        <label>コメント・修正指示
            <textarea name="reviews[{{ $item->id }}][comment]" rows="2" maxlength="2000" placeholder="例：これは不要／名称を変更／下のタスクと一緒にする">{{ $review?->comment }}</textarea>
        </label>
        <label>統合先
            <select name="reviews[{{ $item->id }}][merge_target_item_id]">
                <option value="">統合する場合に選択</option>
                @foreach ($mergeTargets as $target)
                    <option value="{{ $target->id }}" @selected($review?->merge_target_item_id === $target->id)>{{ $target->attributes['title'] ?? $target->target_public_id ?? '名称未設定' }}</option>
                @endforeach
            </select>
        </label>
    </div>
</div>
