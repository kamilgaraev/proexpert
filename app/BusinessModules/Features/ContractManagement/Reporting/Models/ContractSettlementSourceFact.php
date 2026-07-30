<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class ContractSettlementSourceFact extends Model
{
    protected $table = 'contract_settlement_source_facts';

    protected $guarded = [];

    protected $casts = [
        'effective_minor' => 'integer',
        'accepted_minor' => 'integer',
        'completed_cash_minor' => 'integer',
        'source_refs' => 'array',
        'due_at' => 'immutable_date',
        'as_of' => 'immutable_datetime',
    ];
}
