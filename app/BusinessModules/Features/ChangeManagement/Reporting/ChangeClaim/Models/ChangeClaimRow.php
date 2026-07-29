<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use Illuminate\Database\Eloquent\Model;

final class ChangeClaimRow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'occurred_on' => 'immutable_date',
        'source_refs' => 'array',
        'proposed_exposure_minor' => 'integer',
        'approved_exposure_minor' => 'integer',
        'linked_claim_minor' => 'integer',
        'opening_contingency_minor' => 'integer',
        'allocated_contingency_minor' => 'integer',
        'consumed_contingency_minor' => 'integer',
        'released_contingency_minor' => 'integer',
        'closing_contingency_minor' => 'integer',
    ];
}
