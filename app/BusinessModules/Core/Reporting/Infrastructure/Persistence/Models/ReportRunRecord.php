<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportRunRecord extends Model
{
    protected $table = 'report_runs';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'definition_snapshot' => 'array',
        'organization_id' => 'integer',
        'requester_actor_id' => 'integer',
        'saved_view_revision' => 'integer',
        'progress' => 'integer',
        'row_count' => 'integer',
        'scope_holding_organization_ids' => 'array',
        'scope_project_ids' => 'array',
        'scope_resource_ids' => 'array',
        'filters' => 'array',
        'comparison' => 'array',
        'sensitive_column_ids' => 'array',
        'audit_column_ids' => 'array',
        'result_metadata' => 'array',
        'totals' => 'array',
        'quality' => 'array',
        'provenance' => 'array',
        'row_schema' => 'array',
        'capabilities' => 'array',
        'snapshot_watermarks' => 'array',
        'as_of' => 'immutable_datetime',
        'queued_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'ready_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'cancel_requested_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'snapshot_generated_at' => 'immutable_datetime',
        'snapshot_stale_at' => 'immutable_datetime',
        'snapshot_sealed_at' => 'immutable_datetime',
        'execution_lease_expires_at' => 'immutable_datetime',
        'execution_heartbeat_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];
}
