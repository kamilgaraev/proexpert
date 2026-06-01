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
    public const TITLE_LIMIT = 255;

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

    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = self::normalizeTitle($value);
    }

    public static function normalizeTitle(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
        $value = trim($value);

        if (mb_strlen($value) <= self::TITLE_LIMIT) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::TITLE_LIMIT - 3)).'...';
    }

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
