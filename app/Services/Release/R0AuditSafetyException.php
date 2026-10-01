<?php

namespace App\Services\Release;

use RuntimeException;

class R0AuditSafetyException extends RuntimeException
{
    public function __construct(
        private readonly string $safeErrorCode,
        private readonly string $failureStage,
    ) {
        parent::__construct($safeErrorCode);
    }

    public function safeErrorCode(): string
    {
        return $this->safeErrorCode;
    }

    public function failureStage(): string
    {
        return $this->failureStage;
    }
}
