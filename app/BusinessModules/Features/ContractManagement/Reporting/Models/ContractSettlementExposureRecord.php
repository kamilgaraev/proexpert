<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class ContractSettlementExposureRecord extends Model
{
    protected $table = 'contract_settlement_exposure_rows';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'party_id' => 'integer',
        'source_refs' => 'array',
        'effective_minor' => 'integer',
        'accepted_minor' => 'integer',
        'cash_minor' => 'integer',
        'settlement_minor' => 'integer',
        'unperformed_exposure_minor' => 'integer',
        'unpaid_exposure_minor' => 'integer',
    ];
}
