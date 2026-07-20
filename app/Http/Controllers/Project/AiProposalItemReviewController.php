<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\AiProposalItemReview;
use App\Models\AiRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiProposalItemReviewController extends Controller
{
    public function storeRoadmap(Request $request, Project $project, AiProposal $aiProposal): RedirectResponse
    {
        $this->authorizeProposal($request, $project, $aiProposal);
        $validated = $request->validate([
            'reviews' => ['required', 'array', 'min:1', 'max:100'],
            'reviews.*.action' => ['required', Rule::in(array_keys(AiProposalItemReview::actions()))],
            'reviews.*.comment' => ['nullable', 'string', 'max:2000'],
            'reviews.*.merge_target_item_id' => ['nullable', 'integer'],
        ]);

        $items = $aiProposal->items()->whereIn('id', array_keys($validated['reviews']))->get()->keyBy('id');
        if ($items->count() !== count($validated['reviews'])) {
            throw ValidationException::withMessages(['reviews' => '提案に含まれない項目が指定されています。']);
        }

        DB::transaction(function () use ($request, $aiProposal, $items, $validated): void {
            foreach ($validated['reviews'] as $itemId => $reviewInput) {
                $item = $items->get((int) $itemId);
                $action = $reviewInput['action'];
                $comment = trim((string) ($reviewInput['comment'] ?? ''));
                if ($action !== AiProposalItemReview::ACTION_KEEP && $comment === '') {
                    throw ValidationException::withMessages([
                        "reviews.{$itemId}.comment" => '修正・除外・統合にはコメントを入力してください。',
                    ]);
                }

                $mergeTargetId = null;
                if ($action === AiProposalItemReview::ACTION_MERGE) {
                    $mergeTargetId = (int) ($reviewInput['merge_target_item_id'] ?? 0);
                    $mergeTarget = $aiProposal->items()
                        ->whereKey($mergeTargetId)
                        ->where('entity_type', $item->entity_type)
                        ->first();
                    if (! $mergeTarget || $mergeTarget->is($item)) {
                        throw ValidationException::withMessages([
                            "reviews.{$itemId}.merge_target_item_id" => '同じ提案内の別の同種項目を選択してください。',
                        ]);
                    }
                }

                if ($action === AiProposalItemReview::ACTION_KEEP && $comment === '' && ! $mergeTargetId) {
                    $item->review()->delete();
                    continue;
                }

                $item->review()->updateOrCreate([], [
                    'reviewed_by' => $request->user()->id,
                    'action' => $action,
                    'comment' => $comment ?: null,
                    'merge_target_item_id' => $mergeTargetId,
                    'resolved_at' => $action === AiProposalItemReview::ACTION_KEEP ? now() : null,
                ]);
            }
        });

        return back()->with('status', 'ロードマップ配下のレビュー指示を一括保存しました。');
    }

    public function store(Request $request, Project $project, AiProposal $aiProposal, AiProposalItem $item): RedirectResponse
    {
        $this->authorizeProposal($request, $project, $aiProposal, $item);

        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys(AiProposalItemReview::actions()))],
            'comment' => ['nullable', 'string', 'max:2000', 'required_unless:action,keep'],
            'merge_target_item_id' => ['nullable', 'integer', 'required_if:action,merge'],
        ]);

        $mergeTargetId = null;
        if ($validated['action'] === AiProposalItemReview::ACTION_MERGE) {
            $mergeTarget = $aiProposal->items()
                ->whereKey($validated['merge_target_item_id'])
                ->where('entity_type', $item->entity_type)
                ->first();
            if (! $mergeTarget || $mergeTarget->is($item)) {
                throw ValidationException::withMessages([
                    'merge_target_item_id' => '同じ提案内の別の同種項目を選択してください。',
                ]);
            }
            $mergeTargetId = $mergeTarget->id;
        }

        $item->review()->updateOrCreate([], [
            'reviewed_by' => $request->user()->id,
            'action' => $validated['action'],
            'comment' => $validated['comment'] ?? null,
            'merge_target_item_id' => $mergeTargetId,
            'resolved_at' => $validated['action'] === AiProposalItemReview::ACTION_KEEP ? now() : null,
        ]);

        return back()->with('status', '項目へのレビュー指示を保存しました。');
    }

    public function destroy(Request $request, Project $project, AiProposal $aiProposal, AiProposalItem $item): RedirectResponse
    {
        $this->authorizeProposal($request, $project, $aiProposal, $item);
        $item->review()?->delete();

        return back()->with('status', '項目へのレビュー指示を削除しました。');
    }

    public function requestRevision(Request $request, Project $project, AiProposal $aiProposal): RedirectResponse
    {
        $this->authorizeProposal($request, $project, $aiProposal);
        $aiProposal->load(['items.review.mergeTarget']);
        $reviews = $aiProposal->items->pluck('review')->filter()
            ->whereNull('resolved_at')
            ->values();

        if ($reviews->isEmpty()) {
            throw ValidationException::withMessages([
                'reviews' => '修正・除外・統合の未対応指示を1件以上登録してください。',
            ]);
        }

        $instructions = $this->revisionInstructions($aiProposal);
        $aiRequest = DB::transaction(function () use ($request, $project, $aiProposal, $instructions): AiRequest {
            return $project->aiRequests()->create([
                'title' => 'AI提案「'.$aiProposal->title.'」の項目別修正',
                'instructions' => $instructions,
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'requested_by' => $request->user()->id,
                'status' => AiRequest::STATUS_PENDING,
            ]);
        });

        $copyText = "RISE GATE OSのプロジェクト「{$project->name}」にAI修正依頼「{$aiRequest->title}」を登録しました。未処理のAI依頼を確認し、項目別レビュー指示を反映した新しい提案を作成してください。";

        return back()->with([
            'status' => '項目別レビューをまとめたAI修正依頼を登録しました。',
            'ai_request_copy_text' => $copyText,
        ]);
    }

    private function revisionInstructions(AiProposal $proposal): string
    {
        $lines = [
            '元のAI提案を、以下の項目別レビュー指示に従って修正し、新しい提案として再提出してください。',
            '元提案ID: '.$proposal->public_id,
            '元提案名: '.$proposal->title,
            '',
            '【項目別レビュー指示】',
        ];

        foreach ($proposal->items as $item) {
            $review = $item->review;
            if (! $review || $review->resolved_at) {
                continue;
            }
            $title = $item->attributes['title'] ?? $item->target_public_id ?? '名称未設定';
            $lines[] = '- '.strtoupper($item->entity_type).'「'.$title.'」';
            $lines[] = '  対応: '.(AiProposalItemReview::actions()[$review->action] ?? $review->action);
            if ($review->comment) {
                $lines[] = '  指示: '.$review->comment;
            }
            if ($review->mergeTarget) {
                $targetTitle = $review->mergeTarget->attributes['title'] ?? $review->mergeTarget->target_public_id ?? '名称未設定';
                $lines[] = '  統合先: '.$targetTitle;
            }
        }

        $lines[] = '';
        $lines[] = '指示のない項目は維持してください。親子関係と日程整合性を再検証し、検証エラー0件の提案にしてください。';

        return implode("\n", $lines);
    }

    private function authorizeProposal(Request $request, Project $project, AiProposal $proposal, ?AiProposalItem $item = null): void
    {
        Gate::authorize('update', $project);
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace && $project->owning_workspace_id === $workspace->id, 404);
        abort_unless($proposal->project_id === $project->id, 404);
        abort_unless($proposal->status === AiProposal::STATUS_PENDING, 422);
        if ($item) {
            abort_unless($item->ai_proposal_id === $proposal->id, 404);
        }
    }
}
