<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class CustomerSlaPolicyVersion extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'customer_sla_policy_versions';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'customer_organization_id' => 'integer',
        'weekday_intervals' => 'array',
        'holidays' => 'array',
        'pause_statuses' => 'array',
        'first_response_target_seconds' => 'integer',
        'resolution_target_seconds' => 'integer',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
