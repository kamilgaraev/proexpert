<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExecutiveDocumentLegalArchiveVersion extends Model
{
    protected $fillable = [
        'organization_id',
        'executive_document_version_id',
        'legal_archive_document_version_id',
        'source_content_hash',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'executive_document_version_id' => 'integer',
        'legal_archive_document_version_id' => 'integer',
    ];

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocumentVersion::class, 'executive_document_version_id');
    }

    public function legalArchiveVersion(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'legal_archive_document_version_id');
    }
}
