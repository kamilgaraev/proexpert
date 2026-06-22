<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class LegalArchiveDocument extends Model
{
    protected $fillable = [
        'organization_id',
        'primary_project_id',
        'title',
        'document_number',
        'document_type',
        'status',
        'direction',
        'source_system',
        'counterparty_name',
        'document_date',
        'effective_from',
        'effective_until',
        'description',
        'legal_significance_status',
        'edo_status',
        'one_c_status',
        'retention_policy',
        'retention_basis',
        'retention_started_at',
        'retention_until',
        'legal_hold',
        'archived_at',
        'archived_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'document_date' => 'date',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'retention_started_at' => 'datetime',
        'retention_until' => 'datetime',
        'legal_hold' => 'boolean',
        'archived_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'primary_project_id');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(LegalArchiveDocumentVersion::class, 'document_id')->where('is_current', true);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LegalArchiveDocumentVersion::class, 'document_id')->orderByDesc('created_at');
    }

    public function links(): HasMany
    {
        return $this->hasMany(LegalArchiveDocumentLink::class, 'document_id')->orderBy('link_type');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
