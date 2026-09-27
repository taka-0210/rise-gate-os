<?php

namespace App\Services\AiCommon;

use RuntimeException;
use Throwable;

class AiCommonExtractionException extends RuntimeException
{
    public function __construct(public readonly string $safeCode, ?Throwable $previous = null)
    {
        parent::__construct($safeCode, 0, $previous);
    }
}
