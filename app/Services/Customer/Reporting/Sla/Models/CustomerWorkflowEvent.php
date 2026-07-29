<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Models;

use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;
use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class CustomerWorkflowEvent extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'customer_workflow_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'customer_organization_id' => 'integer',
        'project_id' => 'integer',
        'workflow_id' => 'integer',
        'source_version' => 'integer',
        'event_type' => CustomerWorkflowEventType::class,
        'actor_side' => CustomerActorSide::class,
        'actor_id' => 'integer',
        'owner_id' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'evidence' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
