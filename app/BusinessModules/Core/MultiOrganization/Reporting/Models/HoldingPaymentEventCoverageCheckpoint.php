<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class HoldingPaymentEventCoverageCheckpoint extends Model
{
    public $timestamps = false;

    protected $table = 'holding_payment_event_coverage_checkpoints';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'source_max_transaction_id' => 'integer',
            'source_count' => 'integer',
            'captured_count' => 'integer',
            'gap_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('holding_payment_coverage_immutable'));
        self::deleting(static fn (): never => throw new LogicException('holding_payment_coverage_immutable'));
    }
}
