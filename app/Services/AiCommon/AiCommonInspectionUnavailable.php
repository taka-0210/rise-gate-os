<?php

namespace App\Services\AiCommon;

use RuntimeException;

class AiCommonInspectionUnavailable extends RuntimeException
{
    public function __construct(public readonly string $safeCode = 'inspection_unavailable')
    {
        parent::__construct($safeCode);
    }
}
