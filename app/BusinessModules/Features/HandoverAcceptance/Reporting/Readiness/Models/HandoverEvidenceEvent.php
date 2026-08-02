<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class HandoverEvidenceEvent extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'handover_evidence_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'acceptance_scope_id' => 'integer',
        'source_id' => 'integer',
        'source_version' => 'integer',
        'causation_event_id' => 'integer',
        'actor_id' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'evidence' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
