<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services\RequestNotificationPublisher;
use Illuminate\Console\Command;

final class WorkforceRecoverNotifications extends Command
{
    protected $signature = 'workforce:recover-notifications {--limit=100}';

    protected $description = 'Повторить доставку ожидающих уведомлений по заявкам сотрудников';

    public function handle(RequestNotificationPublisher $publisher): int
    {
        $publisher->recover((int) $this->option('limit'));

        return self::SUCCESS;
    }
}
