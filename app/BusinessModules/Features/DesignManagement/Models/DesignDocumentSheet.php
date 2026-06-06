<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignDocumentSheet extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'package_id',
        'section_id',
        'artifact_id',
        'version_id',
        'sheet_number',
        'sheet_code',
        'sheet_title',
        'revision',
        'file_page_number',
        'total_sheets',
        'status',
        'metadata',
    ];

    protected $casts = [
        'file_page_number' => 'integer',
        'total_sheets' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'active',
        'metadata' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(DesignPackage::class, 'package_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(DesignPackageSection::class, 'section_id');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(DesignArtifact::class, 'artifact_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'version_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
