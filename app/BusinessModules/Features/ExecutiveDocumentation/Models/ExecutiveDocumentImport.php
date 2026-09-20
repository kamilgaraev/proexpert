<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExecutiveDocumentImport extends Model
{
    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(ExecutiveDocumentImportItem::class, 'import_id')->orderBy('id');
    }

    public function documentSet(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocumentSet::class, 'document_set_id');
    }
}
