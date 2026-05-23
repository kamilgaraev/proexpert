<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RagSource extends Model
{
    protected $table = 'ai_rag_sources';

    protected $fillable = [
        'organization_id',
        'project_id',
        'source_type',
        'entity_type',
        'entity_id',
        'title',
        'checksum',
        'metadata',
        'indexed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'metadata' => 'array',
        'indexed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(RagChunk::class, 'source_id');
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
