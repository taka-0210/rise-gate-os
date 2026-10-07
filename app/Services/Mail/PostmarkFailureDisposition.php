<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Throwable;

class PostmarkFailureDisposition
{
    public function classify(Throwable $exception): string
    {
        if ($exception instanceof HttpTransportException) {
            try {
                $status = $exception->getResponse()->getStatusCode();
                if ($status === 429) {
                    return 'queued';
                }
                if ($status >= 400 && $status < 500) {
                    return 'failed';
                }
            } catch (Throwable) {
                // An ambiguous transport response cannot authorize a resend.
            }
        }

        return 'delivery_unknown';
    }
}
