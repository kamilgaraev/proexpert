<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionAcceptanceBackfillLedger extends Model
{
    public $timestamps = false;

    protected $table = 'production_acceptance_backfill_ledger';

    protected $fillable = [
        'organization_id',
        'project_id',
        'performance_act_id',
        'recognized_at',
        'status',
        'reason',
        'source_hash',
        'recorded_at',
    ];

    protected $casts = [
        'recognized_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];
}
