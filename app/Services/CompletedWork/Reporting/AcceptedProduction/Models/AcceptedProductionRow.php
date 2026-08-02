<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Models;

use Illuminate\Database\Eloquent\Model;

final class AcceptedProductionRow extends Model
{
    public $timestamps = false;

    protected $table = 'accepted_production_rows';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'source_refs' => 'array',
    ];
}
