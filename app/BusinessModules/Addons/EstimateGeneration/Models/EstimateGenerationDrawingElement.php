<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationDrawingElement extends Model
{
    protected $table = 'estimate_generation_drawing_elements';

    protected $fillable = [
        'session_id',
        'document_id',
        'page_id',
        'organization_id',
        'project_id',
        'type',
        'label',
        'value_text',
        'value_number',
        'unit',
        'bbox',
        'geometry',
        'confidence',
        'source_ref',
        'normalized_payload',
    ];

    protected $casts = [
        'bbox' => 'array',
        'geometry' => 'array',
        'source_ref' => 'array',
        'normalized_payload' => 'array',
        'value_number' => 'float',
        'confidence' => 'float',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocument::class, 'document_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocumentPage::class, 'page_id');
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
