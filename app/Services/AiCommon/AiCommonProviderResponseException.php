<?php

namespace App\Services\AiCommon;

use RuntimeException;

final class AiCommonProviderResponseException extends RuntimeException
{
    public function __construct(string $code, public readonly array $diagnostic)
    {
        parent::__construct($code);
    }
}
