<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HandoverPackageDocument extends Model
{
    protected $fillable = [
        'handover_package_id',
        'title',
        'document_type',
        'is_required',
        'status',
        'external_url',
        'approved_at',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(HandoverPackage::class, 'handover_package_id');
    }
}
