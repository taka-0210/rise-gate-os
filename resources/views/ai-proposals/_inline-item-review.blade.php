@if ($item && $canReview && $proposal->status === \App\Models\AiProposal::STATUS_PENDING)
    @php
        $review = $item->review;
        $mergeTargets = $proposal->items->where('entity_type', $item->entity_type)->where('id', '!=', $item->id);
    @endphp
    <form method="POST" action="{{ route('projects.ai-proposals.items.review.store', [$project, $proposal, $item]) }}" class="proposal-inline-review stack">
        @csrf
        <div class="proposal-inline-review__fields">
            <label>対応
                <select name="action" required>
                    @foreach ($reviewActions as $value => $label)
                        <option value="{{ $value }}" @selected($review?->action === $value || (! $review && $value === 'keep'))>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>コメント・修正指示
                <textarea name="comment" rows="2" maxlength="2000" placeholder="例：これは不要／この名称に変更／下のタスクと一緒にする">{{ $review?->comment }}</textarea>
            </label>
            <label>統合先
                <select name="merge_target_item_id">
                    <option value="">統合する場合に選択</option>
                    @foreach ($mergeTargets as $target)
                        <option value="{{ $target->id }}" @selected($review?->merge_target_item_id === $target->id)>{{ $target->attributes['title'] ?? $target->target_public_id ?? '名称未設定' }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="actions">
            <button type="submit">この項目の指示を保存</button>
            @if ($review)<span class="badge">保存済み</span>@endif
        </div>
    </form>
    @if ($review)
        <form method="POST" action="{{ route('projects.ai-proposals.items.review.destroy', [$project, $proposal, $item]) }}" class="proposal-inline-review__delete">
            @csrf @method('DELETE')
            <button type="submit" class="secondary">指示を削除</button>
        </form>
    @endif
@endif
