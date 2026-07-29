<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class ProcurementProcessEvent extends Model
{
    use ImmutableReportingRecord;

    public const EVENT_CODES = [
        'request_created',
        'request_approved',
        'solicitation_sent',
        'supplier_responded',
        'award_decided',
        'order_sent',
        'first_receipt',
        'fully_received',
        'cancelled',
    ];

    protected $table = 'procurement_process_events';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'event_version' => 'integer',
            'organization_id' => 'integer',
            'purchase_request_id' => 'integer',
            'purchase_request_line_id' => 'integer',
            'project_id' => 'integer',
            'supplier_request_id' => 'integer',
            'supplier_proposal_version_id' => 'integer',
            'purchase_order_id' => 'integer',
            'purchase_receipt_id' => 'integer',
            'actor_id' => 'integer',
            'evidence' => 'array',
        ];
    }
}
