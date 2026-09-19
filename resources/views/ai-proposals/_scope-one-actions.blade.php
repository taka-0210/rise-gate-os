@php
    $latestAttempt = $proposal->applyAttempts->sortByDesc('id')->first();
    $canRetry = ! $latestAttempt || $latestAttempt->retryable;
    $failedResult = $latestAttempt?->itemResults?->firstWhere('status', \App\Models\AiProposalItemResult::STATUS_FAILED);
    $successfulAttempt = $proposal->applyAttempts->firstWhere('status', \App\Models\AiProposalApplyAttempt::STATUS_APPLIED);
@endphp

@if ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_APPROVED
    && $canRetry && config('services.ai.scope_one_apply_enabled', true))
    <div class="card stack" style="border:2px solid #245ca6;background:#f8fbff;">
        <div class="eyebrow">内容確認済み</div>
        <h2>{{ $latestAttempt ? '変更を再試行' : '変更を適用' }}</h2>
        <p>適用直前に権限、提案内容、対象データの版を再確認します。競合や失敗時には変更を一件も反映しません。</p>
        <form method="POST" action="{{ route('projects.ai-proposals.apply', [$project, $proposal]) }}">
            @csrf
            <button type="submit">{{ $latestAttempt ? '安全に再試行' : '変更を適用' }}</button>
        </form>
    </div>
@elseif ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_APPROVED
    && ! config('services.ai.scope_one_apply_enabled', true))
    <div class="card stack"><p>現在、AI提案の適用は一時停止中です。提案内容と履歴は保持されています。</p></div>
@endif

@if ($latestAttempt?->status === \App\Models\AiProposalApplyAttempt::STATUS_PROCESSING)
    <div class="card stack" role="status">
        <h2>変更を適用しています</h2>
        <p>処理結果を確認してから再度操作してください。重複して変更されることはありません。</p>
    </div>
@endif

@if ($latestAttempt && in_array($latestAttempt->status, [
    \App\Models\AiProposalApplyAttempt::STATUS_FAILED,
    \App\Models\AiProposalApplyAttempt::STATUS_CONFLICTED,
], true))
    <div class="card stack" role="alert" style="border:2px solid #c65a46;background:#fff7f5;">
        <h2>変更は反映されていません</h2>
        <p>{{ $latestAttempt->error_message }}</p>
        @if ($failedResult)
            <p class="meta">失敗箇所：提案項目 {{ $failedResult->item?->sort_order ?? '—' }}</p>
        @endif
        @if ($latestAttempt->retryable)
            <p>一時的な失敗です。上の「安全に再試行」から、同じ提案を重複なく再実行できます。</p>
        @elseif ($canReview)
            <form method="POST" action="{{ route('projects.ai-proposals.request-revision', [$project, $proposal]) }}">
                @csrf
                <input type="hidden" name="overall_feedback" value="適用時に現在のProject状態との不一致が見つかりました。最新状態を読み直し、同じ目的の変更を再提案してください。">
                <button type="submit">最新状態から再提案を依頼</button>
            </form>
        @endif
    </div>
@endif

@if ($proposal->status === \App\Models\AiProposal::STATUS_APPLIED && $successfulAttempt)
    <div class="card stack" style="border:2px solid #0f5565;background:#f4fbfc;">
        <h2>{{ $successfulAttempt->applied_items_count }}件の変更を反映しました</h2>
        <div class="actions">
            @foreach ($successfulAttempt->itemResults->where('status', \App\Models\AiProposalItemResult::STATUS_APPLIED) as $result)
                @php
                    $entityType = $result->item?->entity_type;
                    $targetId = $result->applied_entity_public_id;
                    $targetUrl = match ($entityType) {
                        'improvement' => route('projects.improvements.show', [$project, $targetId]),
                        'task' => route('projects.tasks.show', [$project, $targetId]),
                        default => route('projects.show', $project),
                    };
                    $targetLabel = match ($entityType) {
                        'project' => 'Project',
                        'roadmap' => 'Roadmap',
                        'improvement' => '取り組み',
                        'task' => 'Task',
                        default => '変更先',
                    };
                @endphp
                <a class="button secondary" href="{{ $targetUrl }}">{{ $targetLabel }}を確認</a>
            @endforeach
        </div>
    </div>
@endif

@if ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_APPLIED
    && ! $proposal->items->contains('operation', \App\Models\AiProposalItem::OPERATION_CREATE)
    && ! $proposal->undos->contains('status', \App\Models\AiProposalUndo::STATUS_APPLIED))
    <div class="card stack">
        <h2>説明項目を元に戻す</h2>
        <p>適用後に対象が変更されていない場合だけ、今回更新した説明項目を元の値へ復元します。新規作成は元に戻す操作の対象外です。</p>
        <form method="POST" action="{{ route('projects.ai-proposals.undo', [$project, $proposal]) }}">
            @csrf
            <button class="secondary" type="submit">変更前の説明へ戻す</button>
        </form>
    </div>
@endif
