<?php

namespace App\Services\ProductOrganization;

use RuntimeException;

class ProductOrganizationAdmissionRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $entryCode,
        public readonly ?string $targetOrganizationPublicId = null,
    ) {
        parent::__construct('このAccountでは、指定された会社の利用を開始できません。');
    }
}
