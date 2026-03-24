<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationDocument extends Model
{
    protected $table = 'estimate_generation_documents';

    protected $fillable = [
        'session_id',
        'organization_id',
        'project_id',
        'user_id',
        'filename',
        'mime_type',
        'storage_path',
        'extracted_text',
        'structured_payload',
        'meta',
    ];

    protected $casts = [
        'structured_payload' => 'array',
        'meta' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }
}
