<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\CommercialReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileCommercialPaymentsCommand extends Command
{
    protected $signature = 'commercial:reconcile {--limit=100 : Максимальное число объектов за запуск}';

    protected $description = 'Сверить незавершённые коммерческие платежи и возвраты с YooKassa';

    public function handle(CommercialReconciliationService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);
        if ($limit === false) {
            return self::INVALID;
        }

        $result = $service->run($limit);
        Log::info('Commercial payment reconciliation completed.', $result);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
