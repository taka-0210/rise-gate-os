<?php

namespace App\Services\AiCommon;

use App\Models\AiResourcePolicy;
use App\Models\AnnualManagementPolicy;
use App\Models\ManagementDesignItem;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\User;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicySourceProvider;
use App\Services\ManagementDesign\ManagementDesignAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/** Read-only bridge. Viewing a document never grants permission to send it to AI. */
class AiCommonManagementContext
{
    public const TYPES = ['management_design', 'annual_management_policy', 'department_policy'];

    public function __construct(
        private readonly ManagementDesignAccess $management,
        private readonly AnnualManagementPolicySourceProvider $annual,
    ) {}

    public function resolve(User $actor, Organization $organization, string $type, string $publicId): array
    {
        if ($type === 'management_design') {
            $item = ManagementDesignItem::query()->with('currentRevision')
                ->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
            $this->management->authorizeView($actor, $organization, $item->type);
            $revision = $item->currentRevision;
            if (! $revision || $revision->status !== ManagementDesignItem::STATUS_ACTIVE) {
                throw new AuthorizationException;
            }
            $official = $revision->snapshot['item'] ?? [];
            if (($official['public_id'] ?? null) !== $publicId || ($official['type'] ?? null) !== $item->type) {
                throw new AuthorizationException;
            }
            $projection = [
                'title' => ManagementDesignItem::PRESENTATION[$item->type]['label'],
                'revision_no' => $revision->revision_no,
                'source_url' => route('management-design.revisions.show', [$item->type, $revision->revision_no]),
                'content_hash' => hash('sha256', json_encode($official, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'statement' => $official['statement'] ?? null,
                'explanation' => $official['statement_explanation'] ?? null,
                'horizon' => $official['horizon'] ?? null,
                'sections' => $official['sections'] ?? [],
            ];

            return [$item, OrganizationAiPolicy::CATEGORY_MANAGEMENT, $revision->revision_no,
                $projection, null, 'management_design', $item->public_id];
        }

        [$annualId, $departmentId] = array_pad(explode(':', $publicId, 2), 2, null);
        if (($type === 'department_policy') !== ($departmentId !== null)) {
            throw ValidationException::withMessages(['source' => '年度・部署の選択が不正です。']);
        }
        $policy = AnnualManagementPolicy::query()->with(['period', 'currentApprovedRevision'])
            ->where('organization_id', $organization->id)->where('public_id', $annualId)->firstOrFail();
        $export = $this->annual->export($actor, $organization, AnnualManagementPolicySourceProvider::MODE_PERIOD,
            ['annual_public_id' => $annualId]);
        $boundRevision = $policy->revisions()->where('revision_no', $export['revision_no'])->firstOrFail();
        if (! hash_equals($boundRevision->snapshot_hash, $export['content_hash'])) {
            throw new AuthorizationException;
        }
        $official = $boundRevision->snapshot['annual'];
        $content = [
            'purpose' => $official['purpose'] ?? null, 'background' => $official['background'] ?? null,
            'policy' => $official['policy'] ?? null, 'themes' => $official['themes'] ?? [],
        ];
        if ($departmentId !== null) {
            $content = collect($official['departments'] ?? [])->firstWhere('public_id', $departmentId);
            if (! $content) {
                throw ValidationException::withMessages(['source' => '承認済みの部署方針を確認できません。']);
            }
        }
        $projection = [
            'title' => $departmentId === null ? '年度経営方針' : '部署方針',
            'period' => $official['period'], 'revision_no' => $export['revision_no'],
            'content_hash' => $export['content_hash'], 'approval_status' => 'approved',
            'effective_status' => $export['effective_status'], 'timezone' => 'Asia/Tokyo',
            'source_url' => route('annual-management-policy.revisions.show', [$policy, $export['revision_no']]),
            'content' => $content,
        ];
        if ((int) $policy->period->organization_id === (int) $organization->id) {
            $supplement = AiCommonPeriodMetadata::supplement($official, [
                'organization_public_id' => $organization->public_id,
                'public_id' => $policy->period->public_id,
                'name' => $policy->period->name,
                'starts_on' => $policy->period->starts_on?->timezone('Asia/Tokyo')->toDateString(),
                'ends_on' => $policy->period->ends_on?->timezone('Asia/Tokyo')->toDateString(),
                'fiscal_term_number' => $policy->period->fiscal_term_number,
                'version' => $policy->period->version,
            ]);
            if ($supplement !== null) {
                $projection['supplemental_period_metadata'] = $supplement;
            }
        }

        // Bind period correction as well as the immutable approved document.
        return [$policy, OrganizationAiPolicy::CATEGORY_MANAGEMENT,
            $export['revision_no'].':'.$export['currentness']['period_version'], $projection, null,
            'annual_management_policy', $annualId];
    }

    public function catalogue(User $actor, Organization $organization): array
    {
        $candidates = [];
        foreach (ManagementDesignItem::query()->where('organization_id', $organization->id)->get() as $item) {
            $candidates[] = ['type' => 'management_design', 'id' => $item->public_id];
        }
        foreach (AnnualManagementPolicy::query()->with('currentApprovedRevision')->where('organization_id', $organization->id)->get() as $annual) {
            if (! $annual->currentApprovedRevision) {
                continue;
            }
            $candidates[] = ['type' => 'annual_management_policy', 'id' => $annual->public_id];
            foreach ($annual->currentApprovedRevision->snapshot['annual']['departments'] ?? [] as $department) {
                $candidates[] = ['type' => 'department_policy', 'id' => $annual->public_id.':'.$department['public_id']];
            }
        }
        $rows = [];
        foreach ($candidates as $candidate) {
            try {
                $resolved = $this->resolve($actor, $organization, $candidate['type'], $candidate['id']);
            } catch (AuthorizationException|ModelNotFoundException|ValidationException) {
                continue;
            }
            $data = $resolved[3];
            $periodLabel = $data['period']['organization_name'] ?? null;
            if (isset($data['period']['organization_fiscal_term_number'])) {
                $periodLabel = '第'.$data['period']['organization_fiscal_term_number'].'期｜'.$periodLabel;
            }
            $policy = AiResourcePolicy::query()->where('organization_id', $organization->id)
                ->where('resource_type', $resolved[5])->where('resource_public_id', $resolved[6])->first();
            $rows[] = $candidate + [
                'label' => implode(' ／ ', array_filter([$data['title'], $periodLabel,
                    $data['content']['group_name_at_approval'] ?? null, 'Revision '.$data['revision_no']])),
                'policy_type' => $resolved[5], 'policy_id' => $resolved[6],
                'allows_ai_reference' => (bool) $policy?->allows_ai_reference,
                'effective_status' => $data['effective_status'] ?? 'official',
                'state_label' => match ($data['effective_status'] ?? 'official') {
                    'upcoming' => '計画中｜承認済み・開始前', 'effective' => '承認済み・現在有効',
                    'ended' => '過年度｜承認済み', default => '正式版',
                },
            ];
        }

        return $rows;
    }
}
