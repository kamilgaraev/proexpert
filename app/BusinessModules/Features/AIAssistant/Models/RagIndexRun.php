<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagIndexRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const MODE_ASYNC = 'async';
    public const MODE_SYNC = 'sync';
    public const MODE_SCHEDULED = 'scheduled';
    public const MODE_MANUAL = 'manual';

    protected $table = 'ai_rag_index_runs';

    protected $fillable = [
        'organization_id',
        'project_id',
        'source_type',
        'status',
        'mode',
        'queued_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'indexed_chunks',
        'source_count',
        'chunk_count',
        'last_error',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'indexed_chunks' => 'integer',
        'source_count' => 'integer',
        'chunk_count' => 'integer',
    ];

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

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}
