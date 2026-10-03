<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\AnnualManagementPolicy;
use App\Models\AnnualManagementPolicyRevision;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class AnnualManagementPolicySourceProvider
{
    public const NAMESPACE = 'annual_management_policy';
    public const MODE_CURRENT = 'current_period_approved';
    public const MODE_PERIOD = 'period_approved';
    public const MODE_HISTORICAL = 'historical_approved_revision';
    public const MODE_DRAFT = 'draft_explicit';
    public const MODES = [self::MODE_CURRENT, self::MODE_PERIOD, self::MODE_HISTORICAL, self::MODE_DRAFT];

    public function __construct(
        private readonly AnnualManagementPolicyAccess $access,
        private readonly ManagementPeriodResolver $periods,
        private readonly AnnualManagementPolicySnapshot $snapshots,
    ) {}

    public function export(User $actor, Organization $organization, string $mode, array $selector = []): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Annual Source modeが正しくありません。']);
        }
        [$policy, $snapshot, $revisionNo, $contentHash, $status] = $this->resolve($actor, $organization, $mode, $selector);
        $units = $this->units($policy, $snapshot, $mode, $revisionNo, $contentHash);
        $membership = $this->access->authorizeMembership($actor, $organization);
        $evaluatedOn = isset($selector['evaluated_on'])
            ? CarbonImmutable::parse((string) $selector['evaluated_on'], 'Asia/Tokyo')->toDateString()
            : CarbonImmutable::now('Asia/Tokyo')->toDateString();

        return [
            'schema_version' => 1,
            'source_namespace' => self::NAMESPACE,
            'mode' => $mode,
            'approval_status' => $status,
            'organization_public_id' => $organization->public_id,
            'annual_public_id' => $policy->public_id,
            'period_public_id' => $policy->period->public_id,
            'revision_no' => $revisionNo,
            'content_hash' => $contentHash,
            'currentness' => [
                'period_version' => (int) $policy->period->version,
                'relation_version' => (int) ($snapshot['annual']['relation_version'] ?? $policy->relation_version),
                'evaluated_on' => $evaluatedOn,
                'timezone' => 'Asia/Tokyo',
                'membership_access_epoch' => (int) $membership->access_epoch,
                'requires_ai_policy' => true,
            ],
            'units' => $units,
        ];
    }

    public function exportForAudience(array $actors, Organization $organization, string $mode, array $selector = []): array
    {
        if ($actors === []) { throw new AuthorizationException; }
        $exports = array_map(fn (User $actor) => $this->export($actor, $organization, $mode, $selector), $actors);
        $first = $exports[0];
        foreach ($exports as $export) {
            if ($export['content_hash'] !== $first['content_hash'] || count($export['units']) !== count($first['units'])) {
                throw new AuthorizationException;
            }
        }
        return $first;
    }

    public function resolveCitation(User $actor, Organization $organization, string $handle): array
    {
        try {
            $locator = json_decode(Crypt::decryptString($handle), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['citation' => '引用位置を確認できません。']);
        }
        if (($locator['namespace'] ?? null) !== self::NAMESPACE
            || ($locator['organization_public_id'] ?? null) !== $organization->public_id) {
            throw new AuthorizationException;
        }
        $export = $this->export($actor, $organization, (string) $locator['mode'], (array) ($locator['selector'] ?? []));
        $unit = collect($export['units'])->firstWhere('unit_id', $locator['unit_id'] ?? null);
        if (! $unit || ! hash_equals((string) $unit['text_hash'], (string) ($locator['text_hash'] ?? ''))) {
            throw ValidationException::withMessages(['citation' => '引用元が一致しません。']);
        }

        return $unit;
    }

    private function resolve(User $actor, Organization $organization, string $mode, array $selector): array
    {
        if ($mode === self::MODE_CURRENT) {
            $period = $this->periods->current($organization, $selector['evaluated_on'] ?? null);
            if (! $period) { throw ValidationException::withMessages(['period' => '現在の会社期間は登録されていません。']); }
            $policy = AnnualManagementPolicy::query()->with(['organization', 'period', 'currentApprovedRevision'])
                ->where('organization_id', $organization->id)->where('organization_management_period_id', $period->id)->first();
            if (! $policy?->currentApprovedRevision) { throw ValidationException::withMessages(['annual' => '今期の正式方針は未登録です。']); }
            $this->access->authorizeApprovedView($actor, $policy);
            return [$policy, $policy->currentApprovedRevision->snapshot, $policy->currentApprovedRevision->revision_no, $policy->currentApprovedRevision->snapshot_hash, 'approved'];
        }

        $policy = AnnualManagementPolicy::query()->with(['organization', 'period', 'currentApprovedRevision'])
            ->where('organization_id', $organization->id)
            ->when(isset($selector['annual_public_id']), fn ($query) => $query->where('public_id', $selector['annual_public_id']))
            ->when(isset($selector['period_public_id']), fn ($query) => $query->whereHas('period', fn ($period) => $period->where('public_id', $selector['period_public_id'])))
            ->firstOrFail();
        if ($mode === self::MODE_DRAFT) {
            $this->access->authorizeDraftView($actor, $policy);
            if ((int) ($selector['draft_version'] ?? -1) !== (int) $policy->draft_version) {
                throw ValidationException::withMessages(['draft_version' => '作成中の案が更新されています。']);
            }
            $snapshot = $this->snapshots->make($policy);
            return [$policy, $snapshot, (int) $policy->draft_version, $this->snapshots->hash($snapshot), 'draft'];
        }
        $this->access->authorizeApprovedView($actor, $policy);
        $revision = $mode === self::MODE_HISTORICAL || ($mode === self::MODE_PERIOD && isset($selector['revision_no']))
            ? AnnualManagementPolicyRevision::query()->where('annual_management_policy_id', $policy->id)
                ->where('revision_no', (int) ($selector['revision_no'] ?? 0))->firstOrFail()
            : $policy->currentApprovedRevision;
        if (! $revision) { throw ValidationException::withMessages(['annual' => '指定期間の正式方針は未登録です。']); }
        return [$policy, $revision->snapshot, $revision->revision_no, $revision->snapshot_hash, 'approved'];
    }

    private function units(AnnualManagementPolicy $policy, array $snapshot, string $mode, int $revisionNo, string $contentHash): array
    {
        $annual = $snapshot['annual'];
        $citationMode = $mode === self::MODE_DRAFT ? self::MODE_DRAFT : self::MODE_HISTORICAL;
        $selector = $citationMode === self::MODE_DRAFT
            ? ['annual_public_id' => $policy->public_id, 'draft_version' => $revisionNo]
            : ['annual_public_id' => $policy->public_id, 'revision_no' => $revisionNo];
        $units = [];
        $append = function (string $nodeType, string $nodeId, string $kind, ?string $text) use (&$units, $policy, $citationMode, $selector, $contentHash): void {
            if ($text === null || $text === '') { return; }
            $unitId = hash('sha256', implode('|', [$policy->public_id, $citationMode, $nodeType, $nodeId, $kind, $text]));
            $textHash = hash('sha256', $text);
            $locator = [
                'namespace' => self::NAMESPACE, 'organization_public_id' => $policy->organization->public_id,
                'mode' => $citationMode, 'selector' => $selector, 'unit_id' => $unitId, 'text_hash' => $textHash,
            ];
            $units[] = [
                'unit_id' => $unitId, 'node_type' => $nodeType, 'node_public_id' => $nodeId,
                'element_kind' => $kind, 'text' => $text,
                'range' => ['unit' => 'unicode_code_point', 'start' => 0, 'end' => mb_strlen($text)],
                'text_hash' => $textHash, 'content_hash' => $contentHash,
                'citation_handle' => Crypt::encryptString(json_encode($locator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            ];
        };
        $append('annual', $policy->public_id, 'purpose', $annual['purpose'] ?? null);
        $append('annual', $policy->public_id, 'background', $annual['background'] ?? null);
        $append('annual', $policy->public_id, 'policy', $annual['policy'] ?? null);
        foreach ($annual['themes'] ?? [] as $theme) {
            $append('theme', $theme['public_id'], 'statement', $theme['statement'] ?? null);
            $append('theme', $theme['public_id'], 'explanation', $theme['explanation'] ?? null);
            foreach ($theme['priorities'] ?? [] as $priority) {
                $append('priority', $priority['public_id'], 'statement', $priority['statement'] ?? null);
                $append('priority', $priority['public_id'], 'explanation', $priority['explanation'] ?? null);
            }
        }
        foreach ($annual['departments'] ?? [] as $department) {
            $append('department', $department['public_id'], 'introduction', $department['introduction'] ?? null);
            foreach ($department['statements'] ?? [] as $statement) {
                $append('department_statement', $statement['public_id'], 'statement', $statement['statement'] ?? null);
                $append('department_statement', $statement['public_id'], 'explanation', $statement['explanation'] ?? null);
            }
        }
        return $units;
    }
}
