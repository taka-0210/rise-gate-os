<?php

namespace App\Services\BusinessDomain;

use App\Models\BusinessDomain;
use App\Models\BusinessDomainItem;
use App\Models\BusinessDomainItemAttribute;

class BusinessDomainSnapshot
{
    public const SCHEMA_VERSION = 2;

    public function make(BusinessDomain $domain): array
    {
        $domain->load([
            'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'items.attributes' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => 'organization_self_reported',
            'domain' => [
                'public_id' => $domain->public_id,
                'name' => $domain->name,
                'description' => $domain->description,
                'what' => $domain->what_summary,
                'who' => $domain->who_summary,
                'value' => $domain->value_proposition,
                'where' => $domain->geographic_scope_summary,
                'position' => $domain->market_position_summary,
                'self_recognized_strengths' => $domain->self_recognized_strengths,
                'direction' => $domain->direction,
                'direction_memo' => $domain->direction_memo,
                'status' => $domain->status,
                'version' => $domain->version,
                'items' => $domain->items->map(fn (BusinessDomainItem $item): array => [
                    'public_id' => $item->public_id,
                    'kind' => $item->kind,
                    'name' => $item->name,
                    'description' => $item->description,
                    'status' => $item->status,
                    'sort_order' => $item->sort_order,
                    'attributes' => $item->attributes->map(fn (BusinessDomainItemAttribute $attribute): array => [
                        'public_id' => $attribute->public_id,
                        'axis' => $attribute->axis,
                        'label' => $attribute->label,
                        'value' => $attribute->value_text,
                        'status' => $attribute->status,
                        'sort_order' => $attribute->sort_order,
                    ])->values()->all(),
                ])->values()->all(),
            ],
        ];
    }
}
