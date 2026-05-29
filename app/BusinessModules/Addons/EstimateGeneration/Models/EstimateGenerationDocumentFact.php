<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationDocumentFact extends Model
{
    protected $table = 'estimate_generation_document_facts';

    protected $fillable = [
        'document_id',
        'page_id',
        'organization_id',
        'project_id',
        'session_id',
        'fact_type',
        'scope_key',
        'label',
        'value_text',
        'value_number',
        'unit',
        'confidence',
        'source_ref',
        'normalized_payload',
    ];

    protected $casts = [
        'value_number' => 'float',
        'confidence' => 'float',
        'source_ref' => 'array',
        'normalized_payload' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocument::class, 'document_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocumentPage::class, 'page_id');
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
}
