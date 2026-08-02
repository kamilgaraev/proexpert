<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ContingencyLedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'effective_on' => 'immutable_date',
        'effective_at' => 'immutable_datetime',
        'signed_amount_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new DomainException('contingency_ledger_entry_immutable'));
        self::deleting(static fn (): never => throw new DomainException('contingency_ledger_entry_immutable'));
    }
}
