<?php

namespace App\Services\CompanyContextReader;

final readonly class CompanyContextReaderData
{
    /**
     * @param  list<CompanyContextChapterData>  $chapters
     * @param  list<array{value:string,label:string,lifecycle:string,experience:string,experience_label:string,experience_status:string,approval_label:string}>  $annualOptions
     */
    public function __construct(
        public array $chapters,
        public array $annualOptions,
        public ?string $selectedAnnual,
    ) {}
}
