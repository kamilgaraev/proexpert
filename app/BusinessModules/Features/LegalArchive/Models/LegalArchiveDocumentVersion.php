<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalArchiveDocumentVersion extends Model
{
    protected $fillable = [
        'document_id',
        'organization_id',
        'version_number',
        'version_label',
        'is_current',
        'status',
        'file_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'content_hash',
        'metadata_hash',
        'uploaded_by_user_id',
        'uploaded_at',
        'metadata',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'size_bytes' => 'integer',
        'uploaded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
