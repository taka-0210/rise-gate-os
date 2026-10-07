<?php

namespace App\Services\Mail;

use Throwable;

class TransportFailureDisposition
{
    public function classify(string $transport, Throwable $exception): string
    {
        return match ($transport) {
            'postmark' => app(PostmarkFailureDisposition::class)->classify($exception),
            default => 'delivery_unknown',
        };
    }
}
