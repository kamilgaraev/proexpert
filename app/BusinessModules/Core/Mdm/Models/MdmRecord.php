<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdmRecord extends Model
{
    protected $fillable = [
        'organization_id',
        'entity_type',
        'entity_id',
        'display_name',
        'normalized_key',
        'quality_score',
        'quality_issues',
        'normalized_values',
        'status',
        'owner_user_id',
        'version',
        'archived_at',
        'archived_by_user_id',
        'archive_reason',
        'last_synced_at',
    ];

    protected $casts = [
        'quality_score' => 'integer',
        'quality_issues' => 'array',
        'normalized_values' => 'array',
        'version' => 'integer',
        'archived_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function changes(): HasMany
    {
        return $this->hasMany(MdmChangeLog::class);
    }
}
