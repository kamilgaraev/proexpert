<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExecutiveDocumentApprovedList extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'revision', 'approved_by_party', 'approved_at',
        'file_url', 'file_hash', 'original_name', 'items', 'uploaded_by',
    ];

    protected $casts = [
        'approved_at' => 'date',
        'items' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class);
    }
}
