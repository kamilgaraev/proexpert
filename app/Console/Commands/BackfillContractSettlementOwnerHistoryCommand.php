<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerHistoryBackfillService;
use Illuminate\Console\Command;

final class BackfillContractSettlementOwnerHistoryCommand extends Command
{
    protected $signature = 'reports:backfill-contract-settlement-owners {organization_id}';

    protected $description = 'Создаёт исходную точку неизменяемой истории владельцев расчётов по договорам';

    public function handle(ContractSettlementOwnerHistoryBackfillService $service): int
    {
        $checkpoint = $service->backfill((int) $this->argument('organization_id'));
        $this->info('История владельцев расчётов подготовлена: '.$checkpoint->completed_at->format(DATE_ATOM));

        return self::SUCCESS;
    }
}
