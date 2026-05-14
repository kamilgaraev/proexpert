<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentTypeEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ExecutiveDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'document_set_id',
        'created_by',
        'document_type',
        'title',
        'status',
        'work_type_name',
        'section_name',
        'completed_work_id',
        'inspection_date',
        'participants',
        'submitted_at',
        'approved_at',
        'metadata',
    ];

    protected $casts = [
        'document_type' => ExecutiveDocumentTypeEnum::class,
        'status' => ExecutiveDocumentStatusEnum::class,
        'inspection_date' => 'date',
        'participants' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function documentSet(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocumentSet::class, 'document_set_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ExecutiveDocumentVersion::class, 'document_id')->orderByDesc('id');
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(ExecutiveDocumentRemark::class, 'document_id')->orderByDesc('id');
    }

    public function openRemarks(): HasMany
    {
        return $this->remarks()->where('status', 'open');
    }
}
