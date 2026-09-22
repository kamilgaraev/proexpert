<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExecutiveDocumentImportItem extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['staged_path'];
    protected $appends = ['file_received'];
    protected $casts = ['mapping' => 'array', 'errors' => 'array', 'size' => 'integer', 'attempt' => 'integer'];

    public function getFileReceivedAttribute(): bool
    {
        return $this->staged_path !== null || $this->document_id !== null;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocumentImport::class, 'import_id');
    }
}
