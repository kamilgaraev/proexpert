<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\Concerns\RejectsProcurementSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class ProcurementProcessEvent extends Model
{
    use RejectsProcurementSourceMutation;

    public const UPDATED_AT = null;

    protected $table = 'procurement_process_events';

    protected $guarded = ['id'];

    protected $casts = [
        'event_code' => ProcurementProcessEventCode::class,
        'terminal_reason' => ProcurementTerminalReason::class,
        'occurred_at' => 'immutable_datetime',
        'dimension_snapshot' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
