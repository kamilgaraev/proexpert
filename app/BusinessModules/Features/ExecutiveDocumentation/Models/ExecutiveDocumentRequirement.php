<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\Models\CompletedWork;
use App\Models\Organization;
use App\Models\Project;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

final class ExecutiveDocumentRequirement extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'document_set_id', 'work_type_id', 'project_location_id',
        'completed_work_id', 'stage', 'requirement_key', 'title', 'profile_type', 'applicability',
        'source', 'source_revision', 'rule_snapshot', 'coverage_scope', 'evidence',
        'not_applicable_reason', 'not_applicable_by', 'not_applicable_at',
        'superseded_at', 'revision',
        'applicability_reason', 'applicability_by', 'applicability_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'applicability_at' => 'datetime',
        'rule_snapshot' => 'array', 'coverage_scope' => 'array', 'evidence' => 'array',
        'not_applicable_at' => 'datetime', 'superseded_at' => 'datetime',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function documentSet(): BelongsTo { return $this->belongsTo(ExecutiveDocumentSet::class, 'document_set_id'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function workType(): BelongsTo { return $this->belongsTo(WorkType::class); }
    public function projectLocation(): BelongsTo { return $this->belongsTo(ProjectLocation::class); }
    public function completedWork(): BelongsTo { return $this->belongsTo(CompletedWork::class); }
    public function notApplicableBy(): BelongsTo { return $this->belongsTo(User::class, 'not_applicable_by'); }
}
