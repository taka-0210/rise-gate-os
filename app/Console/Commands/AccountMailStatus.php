<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AccountMailStatus extends Command
{
    protected $signature = 'account-mail:status';

    protected $description = 'Read-only Account Mail ledger counts; no recipient, body or credential output.';

    public function handle(): int
    {
        try {
            if (! Schema::hasTable('account_mail_deliveries')) {
                $this->line('ACCOUNT_MAIL_STATUS=SCHEMA_PENDING');

                return self::FAILURE;
            }
            foreach (['queued', 'sending', 'accepted', 'delivered', 'bounced', 'complained', 'failed', 'delivery_unknown'] as $status) {
                $this->line($status.'_count='.DB::table('account_mail_deliveries')->where('status', $status)->count());
            }
            $this->line('ACCOUNT_MAIL_STATUS=PASS');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('ACCOUNT_MAIL_STATUS=UNAVAILABLE');

            return self::FAILURE;
        }
    }
}
