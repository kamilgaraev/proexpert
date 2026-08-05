<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\Models;

use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerTimestamp;
use Illuminate\Database\Eloquent\Model;

final class ContractSettlementOwnerVersion extends Model
{
    protected $table = 'contract_settlement_owner_versions';

    protected $guarded = [];

    protected $dateFormat = ContractSettlementOwnerTimestamp::MODEL_FORMAT;

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];
}
