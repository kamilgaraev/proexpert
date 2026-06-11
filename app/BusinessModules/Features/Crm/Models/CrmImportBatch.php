<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CrmImportBatch extends CrmModel
{
    protected $fillable = [
        'organization_id',
        'entity_type',
        'source_format',
        'status',
        'original_filename',
        'stored_path',
        'total_rows',
        'accepted_rows',
        'warning_rows',
        'blocked_rows',
        'progress_percent',
        'mapping',
        'summary',
        'uploaded_by_user_id',
        'confirmed_by_user_id',
        'confirmed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'mapping' => 'array',
        'summary' => 'array',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(CrmImportRow::class, 'batch_id')->orderBy('row_number');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
