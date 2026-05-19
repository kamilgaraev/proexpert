<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExecutiveDocumentRelation extends Model
{
    protected $fillable = [
        'organization_id',
        'document_id',
        'relation_type',
        'target_type',
        'target_id',
        'label',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocument::class, 'document_id');
    }
}
