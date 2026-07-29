<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ContingencyLedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = ['effective_on' => 'immutable_date', 'signed_amount_minor' => 'integer'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new DomainException('contingency_ledger_entry_immutable'));
        static::deleting(static fn (): never => throw new DomainException('contingency_ledger_entry_immutable'));
    }
}
