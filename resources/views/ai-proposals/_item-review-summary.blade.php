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
            <div class="field">
                <label for="overall_feedback">提案全体への追加指示</label>
                <textarea id="overall_feedback" name="overall_feedback" rows="6" maxlength="5000" placeholder="例：管理画面の作り込みについて、取り組みとタスクを追加してください。元の提案にない観点や、全体の進め方に関する要望を自由に入力できます。">{{ old('overall_feedback') }}</textarea>
                @error('overall_feedback')<div class="error">{{ $message }}</div>@enderror
                <p class="meta">元の提案に存在しない内容も、ここからAIへ追加指示できます。項目別レビューがない場合でも、この欄だけで再提案を依頼できます。</p>
            </div>
            <button type="submit">全体・項目別の指示でAIに再提案を依頼</button>
            <p class="meta">全体への追加指示と、保存済みの項目別レビューをまとめてAIへ返します。指示のない項目は維持します。</p>
        </form>
    @endif
    @if (session('ai_request_copy_text'))
        <div class="card stack">
            <h3>Codexへ送る文章</h3>
            <textarea rows="4" readonly>{{ session('ai_request_copy_text') }}</textarea>
        </div>
    @endif
</div>
