<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Contract\ContractAuditReconciliationService;
use Illuminate\Console\Command;

final class ReconcileContractAuditDebtsCommand extends Command
{
    protected $signature = 'contracts:reconcile-audit-debts {--limit=100}';

    protected $description = 'Повторяет не завершившиеся аудируемые пересчёты договоров';

    public function handle(ContractAuditReconciliationService $service): int
    {
        $this->info((string) $service->reconcile((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
