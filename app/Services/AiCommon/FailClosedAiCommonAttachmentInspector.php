<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonAttachmentInspector;

class FailClosedAiCommonAttachmentInspector implements AiCommonAttachmentInspector
{
    public function inspect(string $binary, array $metadata): array
    {
        throw new AiCommonInspectionUnavailable('attachment_inspector_unavailable');
    }
}
