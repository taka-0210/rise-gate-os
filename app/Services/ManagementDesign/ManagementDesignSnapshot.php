<?php

namespace App\Services\ManagementDesign;

use App\Models\ManagementDesignItem;
use App\Models\ManagementDesignSection;

class ManagementDesignSnapshot
{
    public const SCHEMA_VERSION = 2;

    public function make(ManagementDesignItem $item): array
    {
        $item->load(['sections' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => 'human_official_save',
            'item' => [
                'public_id' => $item->public_id,
                'type' => $item->type,
                'statement' => $item->statement,
                'statement_explanation' => $item->statement_explanation,
                'horizon' => $item->horizon,
                'status' => $item->status,
                'version' => (int) $item->version,
                'sections' => $item->sections->map(fn (ManagementDesignSection $section): array => [
                    'public_id' => $section->public_id,
                    'title' => $section->title,
                    'body' => $section->body,
                    'explanation' => $section->explanation,
                    'horizon' => $section->horizon,
                    'sort_order' => (int) $section->sort_order,
                ])->values()->all(),
            ],
        ];
    }
}
