<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportAuditIntentRecord extends Model
{
    protected $table = 'report_audit_intents';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'actor_id' => 'integer',
        'subject' => 'array',
        'attempt_count' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'available_at' => 'immutable_datetime',
        'lease_expires_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
        'dead_lettered_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
