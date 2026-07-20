<div class="card stack" id="item-reviews">
    <div>
        <div class="eyebrow">項目別レビュー</div>
        <h2>ロードマップ・取り組み・タスクに指示を書く</h2>
        <p>気になる項目だけに「修正・除外・統合」の指示を付けられます。指示のない項目は次の提案でも維持されます。</p>
    </div>
    <div class="grid">
        <div class="card"><div class="meta">全項目</div><h3>{{ $proposal->items->count() }}</h3></div>
        <div class="card"><div class="meta">未対応の指示</div><h3>{{ $unresolvedReviewCount }}</h3></div>
    </div>
    @foreach ($proposal->items as $item)
        @php
            $review = $item->review;
            $title = $item->attributes['title'] ?? $item->target_public_id ?? '名称未設定';
            $entityLabel = match ($item->entity_type) { 'roadmap' => 'ロードマップ', 'improvement' => '取り組み', 'task' => 'タスク', default => $item->entity_type };
            $mergeTargets = $proposal->items->where('entity_type', $item->entity_type)->where('id', '!=', $item->id);
        @endphp
        <article class="stack" style="border-top:1px solid var(--line);padding-top:16px;">
            <div><div class="meta">{{ $entityLabel }}</div><h3 style="margin-bottom:0;">{{ $title }}</h3></div>
            @if ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_PENDING)
                <form method="POST" action="{{ route('projects.ai-proposals.items.review.store', [$project, $proposal, $item]) }}" class="stack">
                    @csrf
                    <div class="grid">
                        <label>対応
                            <select name="action" required>
                                @foreach ($reviewActions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('action', $review?->action ?? 'keep') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>統合先
                            <select name="merge_target_item_id">
                                <option value="">選択してください</option>
                                @foreach ($mergeTargets as $target)
                                    <option value="{{ $target->id }}" @selected((string) old('merge_target_item_id', $review?->merge_target_item_id) === (string) $target->id)>{{ $target->attributes['title'] ?? $target->target_public_id ?? '名称未設定' }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label>コメント・修正指示
                        <textarea name="comment" rows="2" maxlength="2000" placeholder="例：これは不要／名称を○○に変更／下のタスクと一緒にする">{{ old('comment', $review?->comment) }}</textarea>
                    </label>
                    <div class="actions"><button type="submit">指示を保存</button></div>
                </form>
                @if ($review)
                    <form method="POST" action="{{ route('projects.ai-proposals.items.review.destroy', [$project, $proposal, $item]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="secondary">指示を削除</button>
                    </form>
                @endif
            @elseif ($review)
                <div class="card"><strong>{{ $reviewActions[$review->action] ?? $review->action }}</strong>@if ($review->comment)<p>{{ $review->comment }}</p>@endif</div>
            @endif
        </article>
    @endforeach
    @if ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_PENDING)
        <form method="POST" action="{{ route('projects.ai-proposals.request-revision', [$project, $proposal]) }}" class="stack">
            @csrf
            <button type="submit" @disabled($unresolvedReviewCount === 0)>指摘内容でAIに再提案を依頼</button>
            <p class="meta">未対応の「修正・除外・統合」をまとめて、新しいAI依頼を作成します。</p>
        </form>
    @endif
    @if (session('ai_request_copy_text'))
        <div class="card stack"><h3>Codexへ送る文章</h3><textarea rows="4" readonly>{{ session('ai_request_copy_text') }}</textarea></div>
    @endif
</div>
