<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdmDuplicateGroup extends Model
{
    protected $fillable = [
        'organization_id',
        'entity_type',
        'fingerprint',
        'status',
        'confidence',
        'suggested_master_entity_id',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_note',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(MdmDuplicateMember::class, 'duplicate_group_id');
    }
}
