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
}
