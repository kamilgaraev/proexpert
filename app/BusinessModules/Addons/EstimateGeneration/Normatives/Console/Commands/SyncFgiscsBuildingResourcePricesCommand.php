<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceSynchronizationException;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceSynchronizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFgiscsBuildingResourcePricesCommand extends Command
{
    protected $signature = 'estimates:regional-prices:sync-fgiscs-building-resources
        {--bucket=prohelper-storage}
        {--period-id=}
        {--subject-id=}
        {--all-regions}
        {--limit=}
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

            $results = $this->runSync($service, $progress);

            $this->newLine();
            $this->info('Синхронизация сметных цен строительных ресурсов завершена.');
            $this->table(['Показатель', 'Значение'], $this->summaryRows($results));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $context = [
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ];
            if ($exception instanceof FgiscsRegionalPriceSynchronizationException) {
                $context = array_merge($context, $exception->safeContext());
            }
            Log::error('[EstimateGeneration] FGIS CS building resource price sync failed.', $context);
            $this->error(trans_message('estimate_generation.operation_error'));

            return self::FAILURE;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runSync(FgiscsRegionalPriceSynchronizationService $service, callable $progress): array
    {
        $arguments = [
            'bucket' => (string) $this->option('bucket'),
            'periodId' => $this->option('period-id') !== null ? (int) $this->option('period-id') : null,
            'force' => (bool) $this->option('force'),
            'withSplitForm' => ! (bool) $this->option('without-split-form'),
            'progress' => $progress,
        ];

        if ((bool) $this->option('all-regions')) {
            return $service->syncAllRegions(
                ...$arguments,
                limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            );
        }

        if ($this->option('subject-id') !== null) {
            return $service->syncSubject(
                ...$arguments,
                subjectId: (int) $this->option('subject-id'),
            );
        }

        return [$service->syncTatarstan(...$arguments)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array{0:string,1:string}>
     */
    private function summaryRows(array $results): array
    {
        $rows = [
            ['regions', (string) count(array_unique(array_filter(array_map(static fn (array $result): ?string => $result['region'] ?? null, $results))))],
            ['versions', (string) count($results)],
            ['active', (string) count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'active'))],
            ['skipped', (string) count(array_filter($results, static fn (array $result): bool => ($result['skipped'] ?? false) === true))],
            ['unavailable', (string) count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'unavailable'))],
            ['failed', (string) count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'failed'))],
        ];

        foreach (array_slice($results, 0, 20) as $result) {
            $rows[] = [
                (string) ($result['region'] ?? $result['version_key'] ?? 'result'),
                json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $rows;
    }
}
