<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ContractSettlementOwnerHistoryCheckpoint extends Model
{
    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'immutable_datetime',
        'owner_counts' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new DomainException('contract_settlement_owner_checkpoint_immutable'));
        self::deleting(static fn (): never => throw new DomainException('contract_settlement_owner_checkpoint_immutable'));
    }
}
