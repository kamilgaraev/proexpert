<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdmChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'mdm_record_id',
        'entity_type',
        'entity_id',
        'action',
        'before_values',
        'after_values',
        'changed_by_user_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(MdmRecord::class, 'mdm_record_id');
    }
}
