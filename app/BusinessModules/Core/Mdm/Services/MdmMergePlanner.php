<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MdmMergePlanner
{
    public function plan(MdmDuplicateGroup $group, int $masterEntityId): array
    {
        $duplicateIds = $group->members()
            ->where('entity_id', '!=', $masterEntityId)
            ->pluck('entity_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        $references = [];

        foreach ($this->referenceMap($group->entity_type) as $reference) {
            if (!Schema::hasTable($reference['table']) || !Schema::hasColumn($reference['table'], $reference['column'])) {
                continue;
            }

            $query = DB::table($reference['table'])->whereIn($reference['column'], $duplicateIds);

            if (($reference['organization_column'] ?? null) !== null && Schema::hasColumn($reference['table'], $reference['organization_column'])) {
                $query->where($reference['organization_column'], $group->organization_id);
            }

            $ids = $query->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

            if ($ids === []) {
                continue;
            }

            $references[] = [
                'table' => $reference['table'],
                'column' => $reference['column'],
                'organization_column' => $reference['organization_column'] ?? null,
                'affected_ids' => $ids,
                'count' => count($ids),
                'safe_to_update' => true,
            ];
        }

        return [
            'organization_id' => (int) $group->organization_id,
            'entity_type' => $group->entity_type,
            'duplicate_group_id' => (int) $group->id,
            'master_entity_id' => $masterEntityId,
            'duplicate_entity_ids' => $duplicateIds,
            'references' => $references,
            'total_references' => array_sum(array_column($references, 'count')),
        ];
    }

    public function referenceMap(string $entityType): array
    {
        return match ($entityType) {
            'contractor' => [
                ['table' => 'contracts', 'column' => 'contractor_id', 'organization_column' => 'organization_id'],
                ['table' => 'contractor_invitations', 'column' => 'contractor_id', 'organization_column' => 'organization_id'],
                ['table' => 'contractor_verifications', 'column' => 'contractor_id', 'organization_column' => 'organization_id'],
            ],
            'material' => [
                ['table' => 'work_type_materials', 'column' => 'material_id', 'organization_column' => 'organization_id'],
                ['table' => 'warehouse_balances', 'column' => 'material_id', 'organization_column' => 'organization_id'],
                ['table' => 'warehouse_movements', 'column' => 'material_id', 'organization_column' => 'organization_id'],
                ['table' => 'estimate_item_resources', 'column' => 'material_id', 'organization_column' => 'organization_id'],
            ],
            'measurement_unit' => [
                ['table' => 'materials', 'column' => 'measurement_unit_id', 'organization_column' => 'organization_id'],
                ['table' => 'work_types', 'column' => 'measurement_unit_id', 'organization_column' => 'organization_id'],
                ['table' => 'estimate_position_catalog', 'column' => 'measurement_unit_id', 'organization_column' => 'organization_id'],
            ],
            'work_type' => [
                ['table' => 'work_type_materials', 'column' => 'work_type_id', 'organization_column' => 'organization_id'],
                ['table' => 'estimate_position_catalog', 'column' => 'work_type_id', 'organization_column' => 'organization_id'],
                ['table' => 'completed_works', 'column' => 'work_type_id', 'organization_column' => 'organization_id'],
            ],
            default => [],
        };
    }
}
