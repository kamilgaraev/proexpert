<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateGenerationDocumentPage extends Model
{
    protected $table = 'estimate_generation_document_pages';

    protected $fillable = [
        'document_id',
        'processing_unit_id',
        'source_version',
        'output_version',
        'organization_id',
        'project_id',
        'session_id',
        'page_number',
        'width',
        'height',
        'rotation',
        'language_codes',
        'text',
        'text_hash',
        'confidence',
        'raw_payload_path',
        'normalized_payload',
        'quality_flags',
        'status',
        'excluded_at',
        'excluded_reason',
        'retry_attempt_id',
        'last_retry_requested_at',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'rotation' => 'integer',
        'language_codes' => 'array',
        'confidence' => 'float',
        'normalized_payload' => 'array',
        'quality_flags' => 'array',
        'excluded_at' => 'datetime',
        'last_retry_requested_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocument::class, 'document_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function drawingElements(): HasMany
    {
        return $this->hasMany(EstimateGenerationDrawingElement::class, 'page_id')
            ->orderBy('id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(EstimateGenerationDocumentFact::class, 'page_id');
    }

    public function quantityTakeoffs(): HasMany
    {
        return $this->hasMany(EstimateGenerationQuantityTakeoff::class, 'page_id')
            ->orderBy('id');
    }

    public function scopeInferences(): HasMany
    {
        return $this->hasMany(EstimateGenerationScopeInference::class, 'page_id')
            ->orderBy('id');
    }
}
