@php
    $roadmapItem = $roadmap['item_id'] ? $proposal->items->firstWhere('id', $roadmap['item_id']) : null;
    $reviewCount = collect([$roadmapItem])
        ->merge(collect($roadmap['improvements'])->flatMap(function ($improvement) use ($proposal) {
            $items = [$improvement['item_id'] ? $proposal->items->firstWhere('id', $improvement['item_id']) : null];
            foreach ($improvement['tasks'] as $task) {
                $items[] = $task['item_id'] ? $proposal->items->firstWhere('id', $task['item_id']) : null;
            }
            return $items;
        }))
        ->filter()
        ->filter(fn ($item) => $item->review && ! $item->review->resolved_at)
        ->count();
@endphp
@if ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_PENDING)
    <details class="proposal-roadmap-editor">
        <summary>編集{{ $reviewCount ? '（未対応 '.$reviewCount.'件）' : '' }}</summary>
        <form method="POST" action="{{ route('projects.ai-proposals.roadmap-reviews.store', [$project, $proposal]) }}" class="stack">
            @csrf
            @if ($roadmapItem)
                @include('ai-proposals._review-field', ['item' => $roadmapItem, 'label' => 'ロードマップ'])
            @endif
            @foreach ($roadmap['improvements'] as $improvement)
                @php($improvementItem = $improvement['item_id'] ? $proposal->items->firstWhere('id', $improvement['item_id']) : null)
                @if ($improvementItem)
                    @include('ai-proposals._review-field', ['item' => $improvementItem, 'label' => '取り組み'])
                @endif
                @foreach ($improvement['tasks'] as $task)
                    @php($taskItem = $task['item_id'] ? $proposal->items->firstWhere('id', $task['item_id']) : null)
                    @if ($taskItem)
                        @include('ai-proposals._review-field', ['item' => $taskItem, 'label' => 'タスク'])
                    @endif
                @endforeach
            @endforeach
            <div class="actions"><button type="submit">このロードマップを一括保存</button></div>
        </form>
    </details>
@endif
