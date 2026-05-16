<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;

class MdmRelationship extends Model
{
    protected $fillable = [
        'organization_id',
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'relationship_type',
        'strength',
        'metadata',
    ];

    protected $casts = [
        'strength' => 'decimal:2',
        'metadata' => 'array',
    ];
}
