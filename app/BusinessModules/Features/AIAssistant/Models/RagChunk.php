<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagChunk extends Model
{
    protected $table = 'ai_rag_chunks';

    protected $fillable = [
        'source_id',
        'organization_id',
        'project_id',
        'chunk_index',
        'content',
        'content_hash',
        'metadata',
        'embedding_provider',
        'embedding_model',
        'embedding_created_at',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'chunk_index' => 'integer',
        'metadata' => 'array',
        'embedding_created_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(RagSource::class, 'source_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject(Builder $query, ?int $projectId): Builder
    {
        return $projectId === null
            ? $query
            : $query->where('project_id', $projectId);
    }
}
