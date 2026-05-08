<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceUpdateService;
use Illuminate\Console\Command;
use Throwable;

class SyncFgiscsRegionalPricesCommand extends Command
{
    protected $signature = 'estimates:regional-prices:sync-fgiscs
        {--region=RU-TA}
        {--bucket=prohelper-storage}
        {--latest-only}
        {--period-id=}
        {--force}';

    protected $description = 'Синхронизировать квартальные региональные цены ФГИС ЦС для смет.';

    public function handle(FgiscsRegionalPriceUpdateService $service): int
    {
        if ((string) $this->option('region') !== 'RU-TA') {
            $this->error('Сейчас поддерживается только Республика Татарстан.');

            return self::FAILURE;
        }

        try {
            $startedAt = microtime(true);
            $progress = function (string $event, array $payload) use ($startedAt): void {
                $elapsed = (int) floor(microtime(true) - $startedAt);
                $this->line(sprintf('[%s +%ds] %s: %s', now()->format('Y-m-d H:i:s'), $elapsed, $event, json_encode($payload, JSON_UNESCAPED_UNICODE)));
            };

            $result = $service->syncTatarstan(
                bucket: (string) $this->option('bucket'),
                periodId: $this->option('period-id') !== null ? (int) $this->option('period-id') : null,
                latestOnly: (bool) $this->option('latest-only'),
                force: (bool) $this->option('force'),
                progress: $progress,
            );

            $this->newLine();
            $this->info('Синхронизация региональных цен завершена.');
            $this->table(['Показатель', 'Значение'], collect($result)->map(
                static fn ($value, string $key): array => [$key, is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)]
            )->values()->all());

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
