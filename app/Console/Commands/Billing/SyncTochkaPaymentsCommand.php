<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Services\Billing\Bank\BankSyncService;
use Illuminate\Console\Command;

final class SyncTochkaPaymentsCommand extends Command
{
    protected $signature = 'commercial:sync-tochka {--limit=10}';

    protected $description = 'Сверить банковские оплаты МОСТ и подготовить документы';

    public function handle(BankSyncService $service): int
    {
        $result = $service->process((int) $this->option('limit'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
