<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MdmRelationshipService
{
    public function __construct(
        private readonly MdmRelationshipSourceRegistry $sourceRegistry
    ) {
    }

    public function syncOrganization(int $organizationId): array
    {
        $synced = 0;
        $bySource = [];

        foreach ($this->sourceRegistry->sources() as $source) {
            if (!$this->available($source)) {
                $bySource[$source['table']] = ['synced' => 0, 'skipped' => true];
                continue;
            }

            $query = DB::table($source['table'])
                ->whereNotNull($source['source_column'])
                ->whereNotNull($source['target_column']);

            if (($source['organization_column'] ?? null) !== null && Schema::hasColumn($source['table'], $source['organization_column'])) {
                $query->where($source['organization_column'], $organizationId);
            }

            $sourceSynced = 0;

            foreach ($query->get() as $row) {
                $metadata = [];
                foreach ($source['metadata_columns'] ?? [] as $column) {
                    if (property_exists($row, $column)) {
                        $metadata[$column] = $row->{$column};
                    }
                }

                MdmRelationship::query()->updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'source_type' => $source['source_type'] ?? (string) $row->{$source['source_type_column']},
                        'source_id' => (int) $row->{$source['source_column']},
                        'target_type' => $source['target_type'],
                        'target_id' => (int) $row->{$source['target_column']},
                        'relationship_type' => $source['relationship_type'],
                    ],
                    [
                        'strength' => 1,
                        'metadata' => $metadata,
                    ]
                );

                $synced++;
                $sourceSynced++;
            }

            $bySource[$source['table'] . ':' . $source['relationship_type']] = [
                'synced' => $sourceSynced,
                'skipped' => false,
            ];
        }

        return ['relationships_synced' => $synced, 'sources' => $bySource];
    }

    private function available(array $source): bool
    {
        if (!Schema::hasTable($source['table'])) {
            return false;
        }

        foreach ([$source['source_column'], $source['target_column'], $source['organization_column'] ?? null, $source['source_type_column'] ?? null] as $column) {
            if ($column !== null && !Schema::hasColumn($source['table'], $column)) {
                return false;
            }
        }

        return true;
    }
}
