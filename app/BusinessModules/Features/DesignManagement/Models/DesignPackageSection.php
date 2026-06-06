<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDocumentSectionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DesignPackageSection extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'package_id',
        'template_id',
        'code',
        'title',
        'project_stage',
        'object_type',
        'status',
        'required',
        'sort_order',
        'normative_reference',
        'metadata',
    ];

    protected $casts = [
        'project_stage' => DesignProjectStageEnum::class,
        'object_type' => DesignObjectTypeEnum::class,
        'status' => DesignDocumentSectionStatusEnum::class,
        'required' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'not_started',
        'required' => true,
        'sort_order' => 0,
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(DesignDocumentTemplate::class, 'template_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(DesignArtifact::class, 'section_id')->orderBy('document_code')->orderBy('id');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(DesignDocumentSheet::class, 'section_id')->orderBy('file_page_number')->orderBy('sheet_number');
    }

    public function reviewComments(): HasMany
    {
        return $this->hasMany(DesignReviewComment::class, 'section_id')->orderByDesc('id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
