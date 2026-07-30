<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionAcceptanceOwnerVersion extends Model
{
    public $timestamps = false;

    protected $table = 'production_acceptance_owner_versions';

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'performance_act_id',
        'version',
        'event_type',
        'effective_at',
        'source_event_id',
        'source_hash',
        'members',
    ];

    protected $casts = [
        'effective_at' => 'immutable_datetime',
        'members' => 'array',
    ];
}
