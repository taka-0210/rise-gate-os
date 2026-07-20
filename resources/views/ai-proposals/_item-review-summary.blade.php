<div class="card stack" id="item-review-summary">
    <div>
        <div class="eyebrow">項目別レビューの状況</div>
        <h2>指摘をまとめてAIへ返す</h2>
        <p>各ロードマップ・取り組み・タスクの中で保存した指示を、まとめて次のAI提案へ引き継ぎます。</p>
    </div>
    <div class="grid">
        <div class="card"><div class="meta">提案項目</div><h3>{{ $proposal->items->count() }}</h3></div>
        <div class="card"><div class="meta">未対応の指示</div><h3>{{ $unresolvedReviewCount }}</h3></div>
    </div>
    @if ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_PENDING)
        <form method="POST" action="{{ route('projects.ai-proposals.request-revision', [$project, $proposal]) }}" class="stack">
            @csrf
            <button type="submit" @disabled($unresolvedReviewCount === 0)>指摘内容でAIに再提案を依頼</button>
            <p class="meta">「修正・除外・統合」の指示がある項目だけをAIへ返し、指示のない項目は維持します。</p>
        </form>
    @endif
    @if (session('ai_request_copy_text'))
        <div class="card stack">
            <h3>Codexへ送る文章</h3>
            <textarea rows="4" readonly>{{ session('ai_request_copy_text') }}</textarea>
        </div>
    @endif
</div>
