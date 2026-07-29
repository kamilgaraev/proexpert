<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class PurchaseOrderPromiseVersion extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'purchase_order_promise_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'purchase_order_id' => 'integer',
            'purchase_order_item_id' => 'integer',
            'promise_version' => 'integer',
            'supplier_id' => 'integer',
            'project_id' => 'integer',
            'warehouse_id' => 'integer',
            'material_id' => 'integer',
            'supersedes_id' => 'integer',
            'promised_at' => 'immutable_datetime',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'source_version' => 'integer',
            'ordered_value_minor' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
