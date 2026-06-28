<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationScopeInference extends Model
{
    protected $table = 'estimate_generation_scope_inferences';

    protected $fillable = [
        'session_id',
        'document_id',
        'page_id',
        'organization_id',
        'project_id',
        'inference_type',
        'title',
        'description',
        'source_refs',
        'normative_basis',
        'work_intent',
        'confidence',
        'review_required',
        'accepted_at',
    ];

    protected $casts = [
        'source_refs' => 'array',
        'normative_basis' => 'array',
        'work_intent' => 'array',
        'confidence' => 'float',
        'review_required' => 'boolean',
        'accepted_at' => 'datetime',
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
