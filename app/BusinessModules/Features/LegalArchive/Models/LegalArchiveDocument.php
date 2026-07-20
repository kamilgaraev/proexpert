<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class LegalArchiveDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'primary_project_id',
        'title',
        'document_number',
        'document_type',
        'status',
        'type_profile_code',
        'lifecycle_status',
        'approval_status',
        'signature_status',
        'confidentiality_level',
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
        'archived_at',
        'archived_by_user_id',
        'owner_user_id',
        'responsible_user_id',
        'current_primary_version_id',
        'lock_version',
        'structured_fields',
        'activated_at',
        'completed_at',
        'terminated_at',
        'source_type',
        'source_id',
        'source_idempotency_key',
        'source_create_status',
        'source_request_fingerprint',
        'source_create_failure_fingerprint',
        'source_create_failed_at',
        'create_operation_id',
        'create_operation_key',
        'source_create_attempt_token',
        'source_create_attempt_count',
        'source_create_started_at',
        'source_create_heartbeat_at',
        'source_create_lease_expires_at',
        'source_create_retry_action',
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
        'lock_version' => 'integer',
        'structured_fields' => 'array',
        'activated_at' => 'datetime',
        'completed_at' => 'datetime',
        'terminated_at' => 'datetime',
        'metadata' => 'array',
        'source_create_failed_at' => 'datetime',
        'source_create_attempt_count' => 'integer',
        'source_create_started_at' => 'datetime',
        'source_create_heartbeat_at' => 'datetime',
        'source_create_lease_expires_at' => 'datetime',
    ];

    protected function confidentialityLevel(): Attribute
    {
        return Attribute::get(static fn (mixed $value): string => is_string($value) && $value !== '' ? $value : 'internal');
    }

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

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'current_primary_version_id');
    }

    public function currentPrimaryVersion(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'current_primary_version_id')
            ->whereExists(static function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('legal_archive_documents as owner')
                    ->whereColumn('owner.id', 'legal_archive_document_versions.document_id')
                    ->whereColumn('owner.organization_id', 'legal_archive_document_versions.organization_id')
                    ->whereColumn('owner.current_primary_version_id', 'legal_archive_document_versions.id');
            });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LegalArchiveDocumentVersion::class, 'document_id')->orderByDesc('created_at');
    }

    public function files(): HasMany
    {
        return $this->hasMany(LegalArchiveDocumentFile::class, 'document_id')->orderBy('sort_order');
    }

    public function links(): HasMany
    {
        return $this->hasMany(LegalArchiveDocumentLink::class, 'document_id')->orderBy('link_type');
    }

    public function workflowInstances(): HasMany
    {
        return $this->hasMany(LegalWorkflowInstance::class, 'document_id')->orderByDesc('id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(LegalDocumentParty::class, 'document_id')->orderBy('id');
    }

    public function partySnapshotSets(): HasMany
    {
        return $this->hasMany(LegalDocumentPartySnapshotSet::class, 'document_id')->orderBy('id');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(LegalDocumentAccessGrant::class, 'document_id')->orderByDesc('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LegalDocumentComment::class, 'document_id')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
