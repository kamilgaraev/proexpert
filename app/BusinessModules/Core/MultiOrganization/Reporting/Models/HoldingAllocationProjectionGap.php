<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class HoldingAllocationProjectionGap extends Model
{
    protected $table = 'holding_allocation_projection_gaps';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'source_id' => 'integer',
            'source_version' => 'integer',
            'missing_fields' => 'array',
            'observed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
