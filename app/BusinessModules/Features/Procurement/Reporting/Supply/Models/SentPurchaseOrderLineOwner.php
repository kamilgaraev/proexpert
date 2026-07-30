<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class SentPurchaseOrderLineOwner extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'sent_purchase_order_line_owners';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];
}
