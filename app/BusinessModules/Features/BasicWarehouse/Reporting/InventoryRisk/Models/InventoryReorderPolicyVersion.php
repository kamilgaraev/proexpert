<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models;

use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryReorderPolicy;
use App\BusinessModules\Features\BasicWarehouse\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class InventoryReorderPolicyVersion extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'inventory_reorder_policy_versions';

    protected $guarded = [];

    public $timestamps = false;

    public function policy(): InventoryReorderPolicy
    {
        return new InventoryReorderPolicy(
            (string) $this->getAttribute('minimum_quantity'),
            (string) $this->getAttribute('reorder_point_quantity'),
            (string) $this->getAttribute('target_quantity'),
            (string) $this->getAttribute('safety_stock_quantity'),
            (int) $this->getAttribute('lead_time_days'),
            (string) $this->getAttribute('policy_version'),
        );
    }

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'warehouse_id' => 'integer',
            'project_id' => 'integer',
            'material_id' => 'integer',
            'policy_version' => 'integer',
            'lead_time_days' => 'integer',
            'freshness_ttl_seconds' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
