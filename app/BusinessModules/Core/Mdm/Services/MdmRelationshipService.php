<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MdmRelationshipService
{
    public function syncOrganization(int $organizationId): array
    {
        $synced = 0;

        if (Schema::hasTable('work_type_materials')) {
            $rows = DB::table('work_type_materials')
                ->where('organization_id', $organizationId)
                ->get(['work_type_id', 'material_id', 'default_quantity']);

            foreach ($rows as $row) {
                MdmRelationship::query()->updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'source_type' => 'work_type',
                        'source_id' => (int) $row->work_type_id,
                        'target_type' => 'material',
                        'target_id' => (int) $row->material_id,
                        'relationship_type' => 'uses_material',
                    ],
                    [
                        'strength' => 1,
                        'metadata' => ['default_quantity' => $row->default_quantity],
                    ]
                );
                $synced++;
            }
        }

        if (Schema::hasTable('estimate_position_catalog')) {
            $rows = DB::table('estimate_position_catalog')
                ->where('organization_id', $organizationId)
                ->whereNotNull('work_type_id')
                ->get(['id', 'work_type_id']);

            foreach ($rows as $row) {
                MdmRelationship::query()->updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'source_type' => 'estimate_position',
                        'source_id' => (int) $row->id,
                        'target_type' => 'work_type',
                        'target_id' => (int) $row->work_type_id,
                        'relationship_type' => 'based_on_work_type',
                    ],
                    ['strength' => 1]
                );
                $synced++;
            }
        }

        if (Schema::hasTable('warehouse_identifiers')) {
            $rows = DB::table('warehouse_identifiers')
                ->where('organization_id', $organizationId)
                ->whereNotNull('entity_type')
                ->whereNotNull('entity_id')
                ->get(['id', 'entity_type', 'entity_id', 'code']);

            foreach ($rows as $row) {
                MdmRelationship::query()->updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'source_type' => (string) $row->entity_type,
                        'source_id' => (int) $row->entity_id,
                        'target_type' => 'warehouse_identifier',
                        'target_id' => (int) $row->id,
                        'relationship_type' => 'identified_by',
                    ],
                    [
                        'strength' => 1,
                        'metadata' => ['code' => $row->code],
                    ]
                );
                $synced++;
            }
        }

        return ['relationships_synced' => $synced];
    }
}
