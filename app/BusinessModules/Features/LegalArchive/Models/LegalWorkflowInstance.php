<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalWorkflowInstance extends Model
{
    protected $fillable = [
        'organization_id', 'document_id', 'document_version_id', 'document_content_hash',
        'template_id', 'template_version', 'template_snapshot', 'snapshot_hash', 'request_hash',
        'idempotency_key', 'status', 'lock_version', 'submitted_by_user_id', 'submitted_at',
        'due_at', 'completed_at', 'cancelled_at', 'expired_at', 'reconciliation_required_at',
        'reconciliation_reason', 'reconciliation_attempts', 'reconciliation_last_error',
    ];

    protected $casts = [
        'template_version' => 'integer', 'template_snapshot' => 'array', 'lock_version' => 'integer',
        'submitted_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime',
        'cancelled_at' => 'datetime', 'expired_at' => 'datetime', 'reconciliation_required_at' => 'datetime',
        'reconciliation_attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $instance): void {
            $immutable = [
                'organization_id', 'document_id', 'document_version_id', 'document_content_hash',
                'template_id', 'template_version', 'template_snapshot', 'snapshot_hash', 'request_hash',
                'idempotency_key', 'submitted_by_user_id', 'submitted_at',
            ];
            if ($instance->isDirty($immutable)) {
                throw new ImmutableDataException(self::class, 'snapshot_update');
            }
        });
        self::deleting(static fn (self $instance): never => throw new ImmutableDataException(self::class, 'delete'));
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'document_version_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LegalWorkflowTemplate::class, 'template_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LegalWorkflowStep::class, 'instance_id')->orderBy('sequence')->orderBy('step_key');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(LegalWorkflowDecision::class, 'instance_id')->orderBy('id');
    }
}
