<?php
namespace App\Console\Commands;
use App\Services\Notification\NotificationDeliveryProcessor;
use Illuminate\Console\Command;
class ProcessCompanyNotifications extends Command
{
    protected $signature='company-notifications:deliver {--limit=100}';
    protected $description='Deliver a bounded batch of Company OS notifications';
    public function handle(NotificationDeliveryProcessor $processor): int
    {
        $result=$processor->run((int)$this->option('limit'));$this->line(json_encode($result));return self::SUCCESS;
    }
}
