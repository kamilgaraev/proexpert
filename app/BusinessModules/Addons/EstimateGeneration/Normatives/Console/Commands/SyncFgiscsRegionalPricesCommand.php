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
        {--subject-id=}
        {--all-supported}
        {--all-regions}
        {--all-periods}
        {--limit=}
        {--bucket=prohelper-storage}
        {--latest-only}
        {--period-id=}
        {--force}';

    protected $description = 'Синхронизировать квартальные региональные цены ФГИС ЦС для смет.';

    public function handle(FgiscsRegionalPriceUpdateService $service): int
    {
        try {
            $startedAt = microtime(true);
            $progress = function (string $event, array $payload) use ($startedAt): void {
                $elapsed = (int) floor(microtime(true) - $startedAt);
                $this->line(sprintf('[%s +%ds] %s: %s', now()->format('Y-m-d H:i:s'), $elapsed, $event, json_encode($payload, JSON_UNESCAPED_UNICODE)));
            };

            $results = $this->runSync($service, $progress);

            $this->newLine();
            $this->info('Синхронизация региональных цен завершена.');
            $this->table(['Показатель', 'Значение'], $this->summaryRows($results));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(trans_message('estimate_generation.operation_error'));

            return self::FAILURE;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runSync(FgiscsRegionalPriceUpdateService $service, callable $progress): array
    {
        $bucket = (string) $this->option('bucket');
        $periodId = $this->option('period-id') !== null ? (int) $this->option('period-id') : null;
        $allPeriods = (bool) $this->option('all-periods');
        $force = (bool) $this->option('force');

        if ((bool) $this->option('all-regions')) {
            return $service->syncAllRegions(
                bucket: $bucket,
                periodId: $periodId,
                latestOnly: ! (bool) $this->option('all-periods'),
                allPeriods: $allPeriods,
                force: $force,
                limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
                progress: $progress,
            );
        }

        if ((bool) $this->option('all-supported')) {
            return $service->syncSupportedRegions(
                bucket: $bucket,
                periodId: $periodId,
                latestOnly: ! (bool) $this->option('all-periods'),
                allPeriods: $allPeriods,
                force: $force,
                progress: $progress,
            );
        }

        if ($this->option('subject-id') !== null) {
            return $service->syncSubject(
                subjectId: (int) $this->option('subject-id'),
                bucket: $bucket,
                periodId: $periodId,
                latestOnly: ! (bool) $this->option('all-periods'),
                allPeriods: $allPeriods,
                force: $force,
                progress: $progress,
            );
        }

        if ((string) $this->option('region') !== 'RU-TA') {
            throw new \RuntimeException('Для произвольного региона укажите --subject-id или используйте --all-regions.');
        }

        return [
            $service->syncTatarstan(
                bucket: $bucket,
                periodId: $periodId,
                latestOnly: ! (bool) $this->option('all-periods'),
                force: $force,
                progress: $progress,
            ),
        ];
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
