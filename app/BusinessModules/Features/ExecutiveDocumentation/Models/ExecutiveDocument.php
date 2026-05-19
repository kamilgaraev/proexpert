<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentTypeEnum;
use App\Models\ConstructionJournalEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
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
        'work_type_id',
        'work_type_name',
        'section_name',
        'completed_work_id',
        'document_date',
        'copies_count',
        'form_variant',
        'journal_entry_id',
        'inspection_date',
        'participants',
        'profile_data',
        'signatories',
        'submitted_at',
        'approved_at',
        'metadata',
    ];

    protected $casts = [
        'document_type' => ExecutiveDocumentTypeEnum::class,
        'status' => ExecutiveDocumentStatusEnum::class,
        'document_date' => 'date',
        'inspection_date' => 'date',
        'participants' => 'array',
        'profile_data' => 'array',
        'signatories' => 'array',
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

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class, 'work_type_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
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

    public function relations(): HasMany
    {
        return $this->hasMany(ExecutiveDocumentRelation::class, 'document_id');
    }
}
