<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\User;

/**
 * Backward-compatible service name. All callers are forced through the Scope 1 contract.
 */
class AiProposalApplier
{
    public function __construct(private readonly AiProposalScopeOneApplier $scopeOne) {}

    public function apply(AiProposal $proposal, User $actor): AiProposal
    {
        return $this->scopeOne->apply($proposal, $actor);
    }
}
