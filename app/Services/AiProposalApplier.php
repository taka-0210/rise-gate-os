<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\User;

/**
 * Backward-compatible service name. All callers are forced through the Scope 1 contract.
 */
class AiProposalApplier
{
    public function __construct(
        private readonly AiProposalScopeOneApplier $scopeOne,
        private readonly \App\Services\AiCommon\AiCommonUnitAdapter $commonAdapter,
    ) {}

    public function apply(AiProposal $proposal, User $actor): AiProposal
    {
        if (\App\Services\AiCommon\AiCommonProposalContract::supports($proposal->contract_version)) {
            return $this->commonAdapter->apply($proposal, $actor);
        }

        return $this->scopeOne->apply($proposal, $actor);
    }
}
