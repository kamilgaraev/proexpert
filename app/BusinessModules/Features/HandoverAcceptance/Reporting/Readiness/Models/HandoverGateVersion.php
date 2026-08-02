<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class HandoverGateVersion extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'handover_gate_versions';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'acceptance_scope_id' => 'integer',
        'location_id' => 'integer',
        'package_id' => 'integer',
        'gate_version' => 'integer',
        'required_checklist_codes' => 'array',
        'required_document_codes' => 'array',
        'hard_blocker_source_types' => 'array',
        'explicitly_empty_requirements' => 'boolean',
        'due_policy' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
