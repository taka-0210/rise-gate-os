<?php

namespace App\Services\CompanyContextReader;

use App\Models\ManagementDesignItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\ManagementDesign\ManagementDesignAccess;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

class ManagementDesignOfficialResolver
{
    public function __construct(private readonly ManagementDesignAccess $access) {}

    public function resolve(User $actor, Organization $organization, string $type, int $position): ?CompanyContextChapterData
    {
        try {
            $this->access->authorizeView($actor, $organization, $type);
        } catch (AuthorizationException) {
            return null;
        }

        $item = ManagementDesignItem::query()
            ->with('currentRevision')
            ->where('organization_id', $organization->id)
            ->where('type', $type)
            ->first();
        $presentation = ManagementDesignItem::PRESENTATION[$type];
        $identity = $this->identity($type, $position);

        if (! $item?->currentRevision) {
            return new CompanyContextChapterData(
                key: $type,
                ordinal: $identity['ordinal'],
                label: $presentation['label'],
                direction: $presentation['direction'],
                anchor: $type,
                question: $identity['question'],
                available: false,
                documentStatus: null,
                revisionNo: null,
                sourceChangedAt: null,
                statement: null,
                explanation: null,
                horizon: null,
                sections: [],
                managementLinks: $this->managementLinks($actor, $organization, $type, false),
            );
        }

        $revision = $item->currentRevision;
        $snapshot = (array) $revision->snapshot;
        $official = (array) ($snapshot['item'] ?? []);
        if (($official['type'] ?? null) !== $type || ($official['public_id'] ?? null) !== $item->public_id) {
            throw new LogicException('Management Design current revision identity mismatch.');
        }

        return new CompanyContextChapterData(
            key: $type,
            ordinal: $identity['ordinal'],
            label: $presentation['label'],
            direction: $presentation['direction'],
            anchor: $type,
            question: $identity['question'],
            available: true,
            documentStatus: (string) ($official['status'] ?? $revision->status),
            revisionNo: (int) $revision->revision_no,
            sourceChangedAt: $revision->changed_at?->timezone('Asia/Tokyo')->format('Y/m/d H:i').' JST',
            statement: $this->text($official['statement'] ?? null),
            explanation: $this->text($official['statement_explanation'] ?? null),
            horizon: $this->text($official['horizon'] ?? null),
            sections: collect($official['sections'] ?? [])->map(fn ($section): array => [
                'title' => $this->text($section['title'] ?? null),
                'body' => $this->text($section['body'] ?? null),
                'explanation' => $this->text($section['explanation'] ?? null),
                'horizon' => $this->text($section['horizon'] ?? null),
            ])->values()->all(),
            managementLinks: $this->managementLinks($actor, $organization, $type, true),
        );
    }

    private function managementLinks(User $actor, Organization $organization, string $type, bool $exists): array
    {
        $links = [];
        if ($exists) {
            $links[] = ['label' => 'この文書を管理', 'url' => route('management-design.show', $type)];
            $links[] = ['label' => '履歴', 'url' => route('management-design.history', $type)];
        }
        if ($this->access->canEdit($actor, $organization, $type)) {
            $links[] = ['label' => $exists ? '改定' : '正本を登録', 'url' => route('management-design.edit', $type)];
        }

        return $links;
    }

    private function identity(string $type, int $position): array
    {
        return [
            'ordinal' => str_pad((string) $position, 2, '0', STR_PAD_LEFT),
            'question' => match ($type) {
                ManagementDesignItem::TYPE_PHILOSOPHY => '私たちは、何を大切にするのか。',
                ManagementDesignItem::TYPE_VISION => '私たちは、どこへ向かうのか。',
                ManagementDesignItem::TYPE_POLICY => '私たちは、どのようにビジョンを実現するのか。',
            },
        ];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
