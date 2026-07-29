<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionAcceptanceEvent extends Model
{
    public $timestamps = false;

    protected $table = 'production_acceptance_events';

    protected $guarded = [];

    protected $casts = [
        'recognized_at' => 'immutable_datetime',
        'evidence_refs' => 'array',
    ];
}
