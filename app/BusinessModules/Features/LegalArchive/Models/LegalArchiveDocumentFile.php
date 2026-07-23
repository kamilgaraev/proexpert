<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalArchiveDocumentFile extends Model
{
    protected $fillable = [
        'document_id',
        'organization_id',
        'role',
        'title',
        'current_version_id',
        'sort_order',
        'is_required',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_required' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LegalArchiveDocumentVersion::class, 'document_file_id')->orderByDesc('id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'current_version_id');
    }
}
