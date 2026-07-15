<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\CommercialBillingNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ProcessCommercialTrialLifecycleCommand extends Command
{
    protected $signature = 'commercial:process-trial-lifecycle {--at=}';

    protected $description = 'Обрабатывает уведомления и завершение пробного доступа МОСТ';

    public function handle(CommercialBillingNotificationService $service): int
    {
        try {
            $at = $this->option('at') === null
                ? CarbonImmutable::now('Europe/Moscow')
                : CarbonImmutable::parse((string) $this->option('at'), 'Europe/Moscow');
            $service->processTrialLifecycle($at);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Не удалось обработать пробный доступ.');

            return self::FAILURE;
        }
    }
}
