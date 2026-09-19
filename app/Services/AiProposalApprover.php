<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiProposalApprover
{
    public function __construct(private readonly AiProposalValidator $validator, private readonly AiProposalAuthorization $authorization) {}

    public function approve(AiProposal $proposal, User $actor): AiProposal
    {
        if ($proposal->items()->whereHas('review', fn ($query) => $query->whereNull('resolved_at'))->exists()) {
            throw ValidationException::withMessages(['reviews' => '未対応の確認事項があります。修正依頼を完了してから内容を確認してください。']);
        }

        return DB::transaction(function () use ($proposal, $actor): AiProposal {
            $locked = AiProposal::query()->lockForUpdate()->with(['project', 'items'])->findOrFail($proposal->id);
            if ($locked->status !== AiProposal::STATUS_PENDING) {
                throw ValidationException::withMessages(['proposal' => 'この提案は確認待ちではありません。']);
            }
            $this->authorization->authorize($actor, $locked->project, $locked);
            if ($locked->items()->whereHas('review', fn ($query) => $query->whereNull('resolved_at'))->exists()) {
                throw ValidationException::withMessages(['reviews' => '未対応の確認事項があります。修正依頼を完了してから内容を確認してください。']);
            }
            $project = $locked->project()->lockForUpdate()->firstOrFail();
            $locked->setRelation('project', $project);
            $locked = $this->validator->validate($locked)->load(['project', 'items']);
            if ($locked->items->isEmpty() || $locked->items->contains('validation_status', AiProposalValidator::STATUS_INVALID)) {
                throw ValidationException::withMessages(['proposal' => '適用できない内容があります。最新状態から再提案してください。']);
            }
            $hash = AiProposalContract::proposalHash($locked);
            if (! hash_equals((string) $locked->content_hash, $hash)) {
                throw ValidationException::withMessages(['proposal' => '提案内容が変更されています。再確認してください。']);
            }

            $locked->update([
                'status' => AiProposal::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approved_content_hash' => $hash,
                'approved_project_version' => $project->plan_version,
            ]);

            return $locked->fresh(['items', 'approver']);
        });
    }
}
