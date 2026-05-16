<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

class MdmRelationshipSourceRegistry
{
    public function sources(): array
    {
        return [
            ['table' => 'work_type_materials', 'source_type' => 'work_type', 'source_column' => 'work_type_id', 'target_type' => 'material', 'target_column' => 'material_id', 'relationship_type' => 'uses_material', 'organization_column' => 'organization_id', 'metadata_columns' => ['default_quantity']],
            ['table' => 'estimate_position_catalog', 'source_type' => 'estimate_position', 'source_column' => 'id', 'target_type' => 'work_type', 'target_column' => 'work_type_id', 'relationship_type' => 'based_on_work_type', 'organization_column' => 'organization_id', 'metadata_columns' => ['code', 'item_type']],
            ['table' => 'estimate_position_catalog', 'source_type' => 'estimate_position', 'source_column' => 'id', 'target_type' => 'measurement_unit', 'target_column' => 'measurement_unit_id', 'relationship_type' => 'uses_unit', 'organization_column' => 'organization_id', 'metadata_columns' => ['code', 'item_type']],
            ['table' => 'warehouse_identifiers', 'source_type_column' => 'entity_type', 'source_column' => 'entity_id', 'target_type' => 'warehouse_identifier', 'target_column' => 'id', 'relationship_type' => 'identified_by', 'organization_column' => 'organization_id', 'metadata_columns' => ['code', 'status']],
            ['table' => 'warehouse_balances', 'source_type' => 'warehouse', 'source_column' => 'warehouse_id', 'target_type' => 'material', 'target_column' => 'material_id', 'relationship_type' => 'stores_material', 'organization_column' => 'organization_id', 'metadata_columns' => ['quantity']],
            ['table' => 'purchase_request_lines', 'source_type' => 'purchase_request_line', 'source_column' => 'id', 'target_type' => 'material', 'target_column' => 'material_id', 'relationship_type' => 'requests_material', 'organization_column' => 'organization_id', 'metadata_columns' => ['quantity']],
            ['table' => 'estimate_item_resources', 'source_type' => 'estimate_item_resource', 'source_column' => 'id', 'target_type' => 'material', 'target_column' => 'material_id', 'relationship_type' => 'uses_material', 'organization_column' => 'organization_id', 'metadata_columns' => ['quantity']],
        ];
    }
}
