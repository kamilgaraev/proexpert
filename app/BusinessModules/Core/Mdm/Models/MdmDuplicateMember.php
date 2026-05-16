<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdmDuplicateMember extends Model
{
    protected $fillable = [
        'duplicate_group_id',
        'entity_type',
        'entity_id',
        'role',
        'score',
        'evidence',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'evidence' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(MdmDuplicateGroup::class, 'duplicate_group_id');
    }
}
