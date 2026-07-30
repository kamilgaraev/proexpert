<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityDefectPhoto extends Model
{
    protected $fillable = [
        'quality_defect_id',
        'organization_id',
        'uploaded_by',
        'type',
        'url',
        'storage_version_id',
        'storage_etag',
        'storage_sha256',
        'size_bytes',
        'mime_type',
        'storage_identity_verified',
        'caption',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
        'storage_identity_verified' => 'boolean',
    ];

    public function defect(): BelongsTo
    {
        return $this->belongsTo(QualityDefect::class, 'quality_defect_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
