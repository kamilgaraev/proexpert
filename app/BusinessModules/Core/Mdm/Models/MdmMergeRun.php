<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;

class MdmMergeRun extends Model
{
    protected $fillable = [
        'organization_id',
        'duplicate_group_id',
        'entity_type',
        'master_entity_id',
        'duplicate_entity_ids',
        'dry_run_plan',
        'status',
        'applied_by_user_id',
        'applied_at',
    ];

    protected $casts = [
        'duplicate_entity_ids' => 'array',
        'dry_run_plan' => 'array',
        'applied_at' => 'datetime',
    ];
}
