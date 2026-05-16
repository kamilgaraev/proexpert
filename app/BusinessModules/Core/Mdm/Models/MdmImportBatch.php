<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;

class MdmImportBatch extends Model
{
    protected $fillable = [
        'organization_id',
        'entity_type',
        'source',
        'status',
        'total_rows',
        'accepted_rows',
        'rejected_rows',
        'issues',
        'created_by_user_id',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'accepted_rows' => 'integer',
        'rejected_rows' => 'integer',
        'issues' => 'array',
    ];
}
