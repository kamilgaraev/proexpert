<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class SupplyReliabilityRow extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'supply_reliability_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'purchase_order_id' => 'integer',
            'purchase_order_item_id' => 'integer',
            'promise_version_id' => 'integer',
            'supplier_id' => 'integer',
            'project_id' => 'integer',
            'warehouse_id' => 'integer',
            'material_id' => 'integer',
            'original_promised_at' => 'immutable_datetime',
            'first_qualifying_receipt_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'eligible' => 'boolean',
            'on_time' => 'boolean',
            'in_full' => 'boolean',
            'otif' => 'boolean',
            'mature' => 'boolean',
            'stable_in_full' => 'boolean',
            'otif_numerator' => 'integer',
            'eligible_denominator' => 'integer',
            'value_otif_numerator_minor' => 'integer',
            'value_otif_denominator_minor' => 'integer',
            'quality_warnings' => 'array',
        ];
    }
}
