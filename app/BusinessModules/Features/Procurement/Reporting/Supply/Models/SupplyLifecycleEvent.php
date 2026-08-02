<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class SupplyLifecycleEvent extends Model
{
    use ImmutableReportingRecord;

    public const EVENT_TYPES = [
        'sent',
        'confirmed',
        'received',
        'receipt_reversed',
        'returned',
        'cancelled',
    ];

    protected $table = 'supply_lifecycle_events';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'purchase_order_id' => 'integer',
            'purchase_order_item_id' => 'integer',
            'promise_version_id' => 'integer',
            'source_id' => 'integer',
            'source_version' => 'integer',
            'reversed_event_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }
}
