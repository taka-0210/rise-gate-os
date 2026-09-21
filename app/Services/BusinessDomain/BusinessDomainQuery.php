<?php

namespace App\Services\BusinessDomain;

use App\Models\BusinessDomain;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BusinessDomainQuery
{
    public function __construct(private readonly BusinessDomainAccess $access) {}

    public function paginate(
        User $actor,
        Organization $organization,
        string $status = BusinessDomain::STATUS_ACTIVE,
        ?string $search = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $this->access->authorizeView($actor, $organization);
        $perPage = max(1, min(50, $perPage));

        return BusinessDomain::query()
            ->where('organization_id', $organization->id)
            ->when(in_array($status, [BusinessDomain::STATUS_ACTIVE, BusinessDomain::STATUS_ARCHIVED], true),
                fn ($query) => $query->where('status', $status))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('what_summary', 'like', $term)
                        ->orWhere('who_summary', 'like', $term)
                        ->orWhere('value_proposition', 'like', $term)
                        ->orWhere('geographic_scope_summary', 'like', $term)
                        ->orWhere('market_position_summary', 'like', $term)
                        ->orWhere('self_recognized_strengths', 'like', $term)
                        ->orWhere('direction_memo', 'like', $term)
                        ->orWhereHas('items', fn ($items) => $items
                            ->where('name', 'like', $term)
                            ->orWhereHas('attributes', fn ($attributes) => $attributes
                                ->where('label', 'like', $term)
                                ->orWhere('value_text', 'like', $term)));
                });
            })
            ->withCount(['items' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function statusCounts(User $actor, Organization $organization): array
    {
        $this->access->authorizeView($actor, $organization);
        $counts = BusinessDomain::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [BusinessDomain::STATUS_ACTIVE, BusinessDomain::STATUS_ARCHIVED])
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            BusinessDomain::STATUS_ACTIVE => (int) ($counts[BusinessDomain::STATUS_ACTIVE] ?? 0),
            BusinessDomain::STATUS_ARCHIVED => (int) ($counts[BusinessDomain::STATUS_ARCHIVED] ?? 0),
        ];
    }
}
