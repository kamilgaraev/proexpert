<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\CommercialRenewalService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ProcessCommercialRenewalsCommand extends Command
{
    protected $signature = 'commercial:process-renewals {--limit=100} {--at=}';

    protected $description = 'Обрабатывает продление коммерческого доступа МОСТ';

    public function handle(CommercialRenewalService $service): int
    {
        try {
            $at = $this->option('at') === null ? CarbonImmutable::now('Europe/Moscow') : CarbonImmutable::parse((string) $this->option('at'), 'Europe/Moscow');
            $this->line((string) json_encode($service->process($at, max(1, (int) $this->option('limit'))), JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Не удалось обработать продления.');

            return self::FAILURE;
        }
    }
}
