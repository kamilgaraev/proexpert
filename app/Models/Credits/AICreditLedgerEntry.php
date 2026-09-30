<?php

declare(strict_types=1);

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;

final class AICreditLedgerEntry extends Model
{
    protected $table = 'ai_credit_ledger_entries';

    protected $fillable = ['organization_id', 'type', 'amount_minor', 'balance_after_minor', 'reference_type', 'reference_id', 'idempotency_key', 'metadata'];

    protected $casts = ['amount_minor' => 'integer', 'balance_after_minor' => 'integer', 'metadata' => 'array'];
}
