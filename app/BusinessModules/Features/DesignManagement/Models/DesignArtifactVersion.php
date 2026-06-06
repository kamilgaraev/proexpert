<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignVersionStatusEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class DesignArtifactVersion extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'artifact_id',
        'created_by',
        'updated_by',
        'uploaded_by',
        'title',
        'version_number',
        'revision',
        'revision_label',
        'source_format',
        'file_format',
        'source_file_path',
        'source_original_name',
        'source_mime_type',
        'source_size_bytes',
        'source_sha256',
        'page_count',
        'sheet_count',
        'extracted_metadata',
        'model_date',
        'status',
        'is_current',
        'metadata',
    ];

    protected $casts = [
        'status' => DesignVersionStatusEnum::class,
        'source_size_bytes' => 'integer',
        'page_count' => 'integer',
        'sheet_count' => 'integer',
        'extracted_metadata' => 'array',
        'model_date' => 'date',
        'is_current' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'source_format' => 'ifc',
        'file_format' => 'ifc',
        'status' => 'uploaded',
        'is_current' => false,
        'metadata' => '{}',
        'extracted_metadata' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(DesignArtifact::class, 'artifact_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function derivatives(): HasMany
    {
        return $this->hasMany(DesignModelDerivative::class, 'version_id')->orderByDesc('id');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(DesignDocumentSheet::class, 'version_id')->orderBy('file_page_number')->orderBy('sheet_number');
    }

    public function readyDerivative(): HasOne
    {
        return $this->hasOne(DesignModelDerivative::class, 'version_id')
            ->where('status', 'ready')
            ->where('viewer_provider', 'thatopen')
            ->where('derivative_format', 'thatopen_frag');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
