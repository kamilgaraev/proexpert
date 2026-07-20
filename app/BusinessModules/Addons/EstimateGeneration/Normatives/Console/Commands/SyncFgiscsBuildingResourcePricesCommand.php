<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceSynchronizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFgiscsBuildingResourcePricesCommand extends Command
{
    protected $signature = 'estimates:regional-prices:sync-fgiscs-building-resources
        {--bucket=prohelper-storage}
        {--period-id=}
        {--without-split-form}
        {--force}';

    protected $description = 'Синхронизировать региональные сметные цены строительных ресурсов ФГИС ЦС для смет.';

    public function handle(FgiscsRegionalPriceSynchronizationService $service): int
    {
        try {
            $startedAt = microtime(true);
            $progress = function (string $event, array $payload) use ($startedAt): void {
                $elapsed = (int) floor(microtime(true) - $startedAt);
                $this->line(sprintf('[%s +%ds] %s: %s', now()->format('Y-m-d H:i:s'), $elapsed, $event, json_encode($payload, JSON_UNESCAPED_UNICODE)));
            };

            $result = $service->syncTatarstan(
                bucket: (string) $this->option('bucket'),
                periodId: $this->option('period-id') !== null ? (int) $this->option('period-id') : null,
                force: (bool) $this->option('force'),
                withSplitForm: ! (bool) $this->option('without-split-form'),
                progress: $progress,
            );

            $this->newLine();
            $this->info('Синхронизация сметных цен строительных ресурсов завершена.');
            $this->table(['Показатель', 'Значение'], $this->summaryRows($result));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('[EstimateGeneration] FGIS CS building resource price sync failed.', [
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);
            $this->error(trans_message('estimate_generation.operation_error'));

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, array{0:string,1:string}>
     */
    private function summaryRows(array $result): array
    {
        return [
            ['region', (string) ($result['region'] ?? '')],
            ['price_zone', (string) ($result['price_zone'] ?? '')],
            ['period', (string) ($result['period'] ?? '')],
            ['version_id', (string) ($result['version_id'] ?? '')],
            ['version_key', (string) ($result['version_key'] ?? '')],
            ['status', (string) ($result['status'] ?? '')],
            ['files_count', (string) ($result['files_count'] ?? 0)],
            ['rows_read', (string) ($result['rows_read'] ?? 0)],
            ['rows_imported', (string) ($result['rows_imported'] ?? 0)],
            ['errors_count', (string) ($result['errors_count'] ?? 0)],
            ['skipped', (string) ((bool) ($result['skipped'] ?? false) ? 'yes' : 'no')],
        ];
    }
}
