<?php

namespace App\Services\CompanyContextReader;

final readonly class CompanyContextChapterData
{
    public function __construct(
        public string $key,
        public string $ordinal,
        public string $label,
        public string $direction,
        public string $anchor,
        public string $question,
        public bool $available,
        public ?string $documentStatus,
        public ?int $revisionNo,
        public ?string $sourceChangedAt,
        public ?string $statement,
        public ?string $explanation,
        public ?string $horizon,
        public array $sections,
        public array $managementLinks,
        public ?array $annual = null,
    ) {}
}
