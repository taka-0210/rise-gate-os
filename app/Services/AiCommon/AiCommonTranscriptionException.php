<?php

namespace App\Services\AiCommon;

use RuntimeException;

class AiCommonTranscriptionException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly bool $resultUnknown = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($safeCode, 0, $previous);
    }
}
