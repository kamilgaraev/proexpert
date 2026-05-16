<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;

class MdmChangeRequest extends Model
{
    protected $fillable = [
        'organization_id',
        'entity_type',
        'entity_id',
        'action',
        'status',
        'current_values',
        'proposed_values',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'current_values' => 'array',
        'proposed_values' => 'array',
        'reviewed_at' => 'datetime',
    ];
}
