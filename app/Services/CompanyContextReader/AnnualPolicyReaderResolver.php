<?php

namespace App\Services\CompanyContextReader;

use App\Models\AnnualManagementPolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyAccess;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyLifecycle;
use App\Services\AnnualManagementPolicy\ManagementPeriodResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class AnnualPolicyReaderResolver
{
    public function __construct(
        private readonly AnnualManagementPolicyAccess $access,
        private readonly ManagementPeriodResolver $periods,
        private readonly AnnualManagementPolicyLifecycle $lifecycle,
    ) {}

    /** @return array{chapter:?CompanyContextChapterData,options:array,selected:?string} */
    public function resolve(User $actor, Organization $organization, ?string $selector): array
    {
        $this->access->authorizeMembership($actor, $organization);
        $authorized = AnnualManagementPolicy::query()
            ->with(['organization', 'period', 'currentApprovedRevision'])
            ->where('organization_id', $organization->id)
            ->whereNotNull('current_approved_revision_id')
            ->orderByDesc('organization_management_period_id')
            ->get()
            ->filter(fn (AnnualManagementPolicy $policy): bool => $this->access->canViewApproved($actor, $policy))
            ->values();

        $options = $authorized->map(function (AnnualManagementPolicy $policy): array {
            $state = $this->lifecycle->evaluate($policy->period, true);

            return [
                'value' => $policy->period->public_id,
                'label' => $this->periodLabel($policy),
                'lifecycle' => $this->lifecycle->label($state),
            ];
        })->all();

        if ($selector !== null) {
            $policy = $authorized->first(fn (AnnualManagementPolicy $candidate): bool => hash_equals($candidate->period->public_id, $selector));
            if (! $policy) {
                throw (new ModelNotFoundException)->setModel(AnnualManagementPolicy::class);
            }
        } else {
            try {
                $currentPeriod = $this->periods->current($organization);
            } catch (LogicException) {
                $currentPeriod = null;
            }
            $policy = $currentPeriod
                ? $authorized->firstWhere('organization_management_period_id', $currentPeriod->id)
                : null;
        }

        if (! $policy) {
            return ['chapter' => null, 'options' => $options, 'selected' => null];
        }

        $state = $this->lifecycle->evaluate($policy->period, true);
        if ($selector === null && $state['effective_status'] !== 'effective') {
            return ['chapter' => null, 'options' => $options, 'selected' => null];
        }

        return [
            'chapter' => $this->chapter($actor, $policy, $state),
            'options' => $options,
            'selected' => $selector !== null ? $policy->period->public_id : null,
        ];
    }

    private function chapter(User $actor, AnnualManagementPolicy $policy, array $state): CompanyContextChapterData
    {
        $revision = $policy->currentApprovedRevision;
        $annual = (array) (($revision->snapshot['annual'] ?? null) ?: []);
        if (($annual['public_id'] ?? null) !== $policy->public_id) {
            throw new LogicException('Annual Management Policy current revision identity mismatch.');
        }
        $period = (array) ($annual['period'] ?? []);
        $term = $period['organization_fiscal_term_number'] ?? null;
        $name = (string) (($period['declared_name'] ?? null) ?: ($period['organization_name'] ?? $policy->period->name));
        $label = $term ? '第'.$term.'期｜'.$name : $name;
        $links = [
            ['label' => '年度方針を管理', 'url' => route('annual-management-policy.show', $policy)],
            ['label' => '履歴', 'url' => route('annual-management-policy.history', $policy)],
        ];
        if ($this->access->canEdit($actor, $policy)) {
            $links[] = ['label' => '改定', 'url' => route('annual-management-policy.edit', $policy)];
        }

        return new CompanyContextChapterData(
            key: 'annual',
            ordinal: '04',
            label: '年度経営方針',
            direction: 'NOW',
            anchor: 'annual',
            question: '今期、何を重要と考え、何を変えるのか。',
            available: true,
            documentStatus: 'approved',
            revisionNo: (int) $revision->revision_no,
            sourceChangedAt: $revision->approved_at?->timezone('Asia/Tokyo')->format('Y/m/d H:i').' JST',
            statement: $this->text($annual['policy'] ?? null),
            explanation: null,
            horizon: null,
            sections: [],
            managementLinks: $links,
            annual: [
                'period_label' => $label,
                'starts_on' => (string) (($period['declared_starts_on'] ?? null) ?: ($period['organization_starts_on'] ?? $policy->period->starts_on->toDateString())),
                'ends_on' => (string) (($period['declared_ends_on'] ?? null) ?: ($period['organization_ends_on'] ?? $policy->period->ends_on->toDateString())),
                'approval_status' => $state['approval_status'],
                'effective_status' => $state['effective_status'],
                'lifecycle_label' => $this->lifecycle->label($state),
                'timezone' => $state['timezone'],
                'purpose' => $this->text($annual['purpose'] ?? null),
                'background' => $this->text($annual['background'] ?? null),
                'themes' => collect($annual['themes'] ?? [])->map(fn ($theme): array => [
                    'statement' => $this->text($theme['statement'] ?? null),
                    'explanation' => $this->text($theme['explanation'] ?? null),
                    'priorities' => collect($theme['priorities'] ?? [])->map(fn ($priority): array => [
                        'statement' => $this->text($priority['statement'] ?? null),
                        'explanation' => $this->text($priority['explanation'] ?? null),
                    ])->values()->all(),
                ])->values()->all(),
                'departments' => collect($annual['departments'] ?? [])->map(fn ($department): array => [
                    'name' => $this->text($department['group_name_at_approval'] ?? null),
                    'introduction' => $this->text($department['introduction'] ?? null),
                    'statements' => collect($department['statements'] ?? [])->map(fn ($statement): array => [
                        'statement' => $this->text($statement['statement'] ?? null),
                        'explanation' => $this->text($statement['explanation'] ?? null),
                    ])->values()->all(),
                ])->values()->all(),
            ],
        );
    }

    private function periodLabel(AnnualManagementPolicy $policy): string
    {
        $annual = (array) (($policy->currentApprovedRevision?->snapshot['annual'] ?? null) ?: []);
        $period = (array) ($annual['period'] ?? []);
        $term = $period['organization_fiscal_term_number'] ?? null;
        $name = (string) (($period['declared_name'] ?? null) ?: ($period['organization_name'] ?? $policy->period->name));

        return $term ? '第'.$term.'期｜'.$name : $name;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
