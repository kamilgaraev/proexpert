<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class HandoverReadinessRow extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'handover_readiness_rows';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'acceptance_scope_id' => 'integer',
        'location_id' => 'integer',
        'package_id' => 'integer',
        'due_on' => 'immutable_date',
        'mandatory_completeness' => 'decimal:8',
        'document_completeness' => 'decimal:8',
        'open_hard_blocker_count' => 'integer',
        'attempt_count' => 'integer',
        'successful_result_count' => 'integer',
        'ready' => 'boolean',
        'evidence_refs' => 'array',
    ];
}
