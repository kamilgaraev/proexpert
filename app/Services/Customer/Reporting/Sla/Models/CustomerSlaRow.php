<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class CustomerSlaRow extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'customer_sla_rows';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'customer_organization_id' => 'integer',
        'workflow_id' => 'integer',
        'owner_id' => 'integer',
        'opened_at' => 'immutable_datetime',
        'first_response_seconds' => 'integer',
        'resolution_seconds' => 'integer',
        'open_aging_seconds' => 'integer',
        'first_response_breached' => 'boolean',
        'resolution_breached' => 'boolean',
        'actor_side_complete' => 'boolean',
        'event_refs' => 'array',
    ];
}
