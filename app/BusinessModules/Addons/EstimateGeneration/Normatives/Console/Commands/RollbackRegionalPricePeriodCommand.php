<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceActivationService;
use Illuminate\Console\Command;
use Throwable;

class RollbackRegionalPricePeriodCommand extends Command
{
    protected $signature = 'estimates:regional-prices:rollback
        {--region=RU-TA}
        {--price-zone=202}';

    protected $description = 'Вернуть предыдущий активный квартал региональных цен смет.';

    public function handle(RegionalPriceActivationService $service): int
    {
        try {
            $activation = $service->rollback(
                regionCode: (string) $this->option('region'),
                priceZoneId: (int) $this->option('price-zone'),
            );

            $this->info('Активный квартал региональных цен восстановлен.');
            $this->table(['Показатель', 'Значение'], [
                ['Регион', (string) $this->option('region')],
                ['Ценовая зона ФГИС', (string) $this->option('price-zone')],
                ['Активный период', $activation->activeVersion?->period?->name ?? ''],
                ['Предыдущий период', $activation->previousVersion?->period?->name ?? ''],
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(trans_message('estimate_generation.operation_error'));

            return self::FAILURE;
        }
    }
}
