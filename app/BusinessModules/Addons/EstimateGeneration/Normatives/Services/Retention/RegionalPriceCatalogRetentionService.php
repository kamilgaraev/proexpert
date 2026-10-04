<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Retention;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class RegionalPriceCatalogRetentionService
{
    public const MUTEX = 'estimate-fgiscs-sync';

    /** @return array<string, mixed> */
    public function prune(bool $execute = false, int $quarters = 4, int $failedDays = 45, int $limit = 100000, int $batch = 1000, int $maxSeconds = 240): array
    {
        if ($quarters < 4 || $failedDays < 45 || $limit < 1 || $batch < 1 || $batch > 5000 || $maxSeconds < 1 || $maxSeconds > 600) {
            throw new InvalidArgumentException('Invalid regional price retention limits.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Regional price catalog retention requires PostgreSQL.');
        }

        // The final transaction can execute several guarded statements after the budget expires.
        $lock = Cache::lock(self::MUTEX, $maxSeconds + 600);
        if (! $lock->get()) {
            return ['mode' => $execute ? 'execute' : 'dry-run', 'busy' => true, 'deleted_rows' => 0, 'deleted_versions' => 0];
        }

        $deadline = microtime(true) + $maxSeconds;
        $result = ['mode' => $execute ? 'execute' : 'dry-run', 'busy' => false, 'eligible_versions' => [], 'protected_versions' => [],
            'blocked_versions' => [], 'deleted_rows' => 0, 'deleted_versions' => 0, 'limit_reached' => false];

        try {
            $versions = DB::table('estimate_regional_price_versions as v')->join('estimate_price_periods as p', 'p.id', '=', 'v.period_id')
                ->where('v.source', 'fgis_labor_prices')
                ->select('v.id', 'v.source', 'v.region_id', 'v.price_zone_id', 'v.status', 'v.activated_at', 'v.updated_at', 'p.year', 'p.quarter')->get()
                ->map(static fn (object $version): array => (array) $version)->all();
            $ids = (new RegionalPriceRetentionPolicy)->candidates($versions, $quarters, now()->subDays($failedDays)->toDateTimeString());

            foreach ($ids as $versionId) {
                if (microtime(true) >= $deadline || $result['deleted_rows'] >= $limit) {
                    $result['limit_reached'] = true;
                    break;
                }
                try {
                    if (! $execute) {
                        $allowed = DB::transaction(function () use ($versionId, $quarters, $failedDays, $deadline): bool {
                            $this->configureTransaction($deadline);

                            return $this->allowed($versionId, $quarters, $failedDays);
                        });
                        if ($allowed) {
                            $result['eligible_versions'][] = $versionId;
                        } else {
                            $result['protected_versions'][] = $versionId;
                        }

                        continue;
                    }

                    do {
                        $remaining = $limit - $result['deleted_rows'];
                        if ($remaining <= 0 || microtime(true) >= $deadline) {
                            $result['limit_reached'] = true;
                            break;
                        }
                        $change = DB::transaction(function () use ($versionId, $quarters, $failedDays, $batch, $remaining, $deadline): array {
                            $this->configureTransaction($deadline);
                            DB::select('SELECT public.eg_regional_price_retention_lock_evidence()');
                            $version = DB::table('estimate_regional_price_versions')->where('id', $versionId)->lockForUpdate()->first();
                            if ($version === null || ! $this->allowed($versionId, $quarters, $failedDays)) {
                                return ['protected' => true, 'rows' => 0, 'version' => 0];
                            }
                            $priceIds = DB::table('estimate_resource_prices')->where('regional_price_version_id', $versionId)
                                ->limit(min($batch, $remaining))->lockForUpdate()->pluck('id')->all();
                            $deleted = $priceIds === [] ? 0 : DB::table('estimate_resource_prices')->whereIn('id', $priceIds)->delete();
                            $versionDeleted = 0;
                            if (! DB::table('estimate_resource_prices')->where('regional_price_version_id', $versionId)->exists()) {
                                $versionDeleted = DB::table('estimate_regional_price_versions')->where('id', $versionId)->delete();
                            }

                            return ['protected' => false, 'rows' => $deleted, 'version' => $versionDeleted];
                        });
                        if ($change['protected']) {
                            $result['protected_versions'][] = $versionId;
                            break;
                        }
                        if (! in_array($versionId, $result['eligible_versions'], true)) {
                            $result['eligible_versions'][] = $versionId;
                        }
                        $result['deleted_rows'] += $change['rows'];
                        $result['deleted_versions'] += $change['version'];
                    } while ($change['rows'] > 0 && $change['version'] === 0);
                } catch (QueryException $exception) {
                    $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
                    if (! in_array($sqlState, ['P0001', '23503', '55P03', '57014', '40P01', '40001'], true)) {
                        throw $exception;
                    }
                    $result['blocked_versions'][] = ['id' => $versionId, 'sqlstate' => $sqlState];
                }
            }
        } finally {
            $lock->release();
        }

        return $result;
    }

    private function configureTransaction(float $deadline): void
    {
        $timeout = max(1, min(60000, (int) (($deadline - microtime(true)) * 1000)));
        DB::statement("SET LOCAL statement_timeout = '{$timeout}ms'");
        DB::statement("SET LOCAL lock_timeout = '1000ms'");
        DB::statement("SET LOCAL work_mem = '64MB'");
    }

    private function allowed(int $versionId, int $quarters, int $failedDays): bool
    {
        return in_array(DB::scalar(
            'SELECT public.eg_regional_price_retention_eligible(?, ?, ?) AND NOT public.eg_regional_price_retention_referenced(?)',
            [$versionId, $quarters, $failedDays, $versionId]
        ), [true, 1, '1', 't'], true);
    }
}
