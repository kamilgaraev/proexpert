<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EstimateImportStatisticsService
{
    private const VERSIONS_TABLE = 'estimate_dataset_versions';
    private const ERRORS_TABLE = 'estimate_import_errors';

    /**
     * @return array<string, mixed>
     */
    public function inspect(?string $sourceType = null, ?string $versionKey = null, int $errorsLimit = 50): array
    {
        return [
            'versions' => $this->listVersions($sourceType, $versionKey),
            'errors' => $this->listErrors($sourceType, $versionKey, $errorsLimit),
            'totals' => $this->totals(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listVersions(?string $sourceType = null, ?string $versionKey = null): array
    {
        if (!Schema::hasTable(self::VERSIONS_TABLE)) {
            return [];
        }

        $query = DB::table(self::VERSIONS_TABLE)
            ->select([
                'id',
                'source_type',
                'version_key',
                'bucket',
                'prefix',
                'status',
                'files_count',
                'rows_read',
                'rows_imported',
                'errors_count',
                'started_at',
                'finished_at',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($sourceType !== null && $sourceType !== '') {
            $query->where('source_type', $sourceType);
        }

        if ($versionKey !== null && $versionKey !== '') {
            $query->where('version_key', $versionKey);
        }

        return $query
            ->limit(100)
            ->get()
            ->map(static fn (object $version): array => (array) $version)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listErrors(?string $sourceType = null, ?string $versionKey = null, int $limit = 50): array
    {
        if (!Schema::hasTable(self::VERSIONS_TABLE) || !Schema::hasTable(self::ERRORS_TABLE)) {
            return [];
        }

        $query = DB::table(self::ERRORS_TABLE . ' as errors')
            ->join(self::VERSIONS_TABLE . ' as versions', 'versions.id', '=', 'errors.dataset_version_id')
            ->select([
                'errors.id',
                'versions.source_type',
                'versions.version_key',
                'errors.source_file',
                'errors.row_number',
                'errors.node_path',
                'errors.severity',
                'errors.message',
                'errors.created_at',
            ])
            ->orderByDesc('errors.created_at')
            ->orderByDesc('errors.id')
            ->limit(max(1, min($limit, 200)));

        if ($sourceType !== null && $sourceType !== '') {
            $query->where('versions.source_type', $sourceType);
        }

        if ($versionKey !== null && $versionKey !== '') {
            $query->where('versions.version_key', $versionKey);
        }

        return $query
            ->get()
            ->map(static fn (object $error): array => (array) $error)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function totals(): array
    {
        $tables = [
            'resources' => 'construction_resources',
            'collections' => 'estimate_norm_collections',
            'sections' => 'estimate_norm_sections',
            'norms' => 'estimate_norms',
            'norm_resources' => 'estimate_norm_resources',
            'prices' => 'estimate_resource_prices',
        ];

        $totals = [];

        foreach ($tables as $key => $table) {
            $totals[$key] = !Schema::hasTable($table) ? 0 : ($key === 'prices' ? $this->priceCount() : DB::table($table)->count());
        }

        return $totals;
    }

    private function priceCount(): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->getTablePrefix() !== ''
            || $connection->getConfig('read') !== null || $connection->getConfig('write') !== null
            || ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction())) {
            return $connection->table('estimate_resource_prices')->count();
        }

        return $connection->transaction(function () use ($connection): int {
            $connection->statement('LOCK TABLE "estimate_resource_prices" IN ACCESS SHARE MODE');
            $guard = $connection->selectOne("SELECT to_regclass('estimate_resource_prices')=to_regclass('public.estimate_resource_prices') AS source_matches,
                to_regclass('public.estimate_resource_price_count_deltas') IS NOT NULL AS counter_exists,
                has_table_privilege(to_regclass('public.estimate_resource_price_count_deltas'),'SELECT') AS counter_readable,
                row_security_active('estimate_resource_prices'::regclass) AS row_security,pg_is_in_recovery() AS recovery");
            if ($guard === null || $guard->source_matches !== true || $guard->counter_exists !== true || $guard->counter_readable !== true
                || $guard->row_security !== false || $guard->recovery !== false) {
                return $connection->table('estimate_resource_prices')->count();
            }

            $result = $connection->selectOne("SELECT CASE WHEN current_setting('transaction_isolation') IN ('read committed','read uncommitted')
                THEN (SELECT COALESCE(sum(delta),0)::bigint FROM public.estimate_resource_price_count_deltas)
                ELSE (SELECT count(*) FROM public.estimate_resource_prices) END AS aggregate");

            return (int) $result->aggregate;
        });
    }
}
