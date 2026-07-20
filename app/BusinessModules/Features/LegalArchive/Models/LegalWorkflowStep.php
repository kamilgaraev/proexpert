<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalWorkflowStep extends Model
{
    private ?int $authorizedReassignmentDecisionId = null;

    private bool $authorizedRecoveryProjection = false;

    protected $fillable = [
        'instance_id', 'organization_id', 'step_key', 'label', 'sequence', 'parallel_group',
        'required', 'policy_key', 'actor_type', 'actor_reference', 'status', 'lock_version',
        'due_in_hours', 'deadline_at', 'activated_at', 'due_at', 'completed_at',
        'assignment_revision', 'last_reassign_decision_id',
    ];

    protected $casts = [
        'sequence' => 'integer', 'required' => 'boolean', 'lock_version' => 'integer', 'due_in_hours' => 'integer',
        'deadline_at' => 'datetime', 'activated_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime',
        'assignment_revision' => 'integer',
        'last_reassign_decision_id' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $step): void {
            $immutable = [
                'instance_id', 'organization_id', 'step_key', 'label', 'sequence', 'parallel_group',
                'required', 'policy_key', 'due_in_hours', 'deadline_at',
            ];
            if ($step->isDirty($immutable)) {
                throw new ImmutableDataException(self::class, 'snapshot_update');
            }
            $assignmentFields = ['actor_type', 'actor_reference', 'due_at', 'assignment_revision', 'last_reassign_decision_id'];
            if (! $step->isDirty($assignmentFields)) {
                return;
            }
            $activationOnly = $step->getOriginal('status') === 'pending'
                && $step->status === 'active'
                && ! $step->isDirty(['actor_type', 'actor_reference', 'assignment_revision', 'last_reassign_decision_id']);
            if (! $activationOnly && $step->authorizedReassignmentDecisionId === null && ! $step->authorizedRecoveryProjection) {
                throw new ImmutableDataException(self::class, 'assignment_update');
            }
        });
        self::deleting(static fn (self $step): never => throw new ImmutableDataException(self::class, 'delete'));
    }

    public function applyReassignment(LegalWorkflowDecision $decision): void
    {
        if (
            ! $decision->exists
            || $decision->action !== 'reassign'
            || (int) $decision->step_id !== (int) $this->id
            || (int) $decision->instance_id !== (int) $this->instance_id
            || (int) $decision->organization_id !== (int) $this->organization_id
            || (int) $decision->assignment_revision !== ((int) $this->assignment_revision) + 1
        ) {
            throw new ImmutableDataException(self::class, 'reassignment_decision_invalid');
        }
        $this->authorizedReassignmentDecisionId = (int) $decision->id;
        try {
            $this->forceFill([
                'actor_type' => $decision->to_actor_type,
                'actor_reference' => $decision->to_actor_reference,
                'due_at' => $decision->to_due_at,
                'assignment_revision' => (int) $decision->assignment_revision,
                'last_reassign_decision_id' => (int) $decision->id,
                'lock_version' => ((int) $this->lock_version) + 1,
            ])->save();
        } finally {
            $this->authorizedReassignmentDecisionId = null;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function applyRecoveryProjection(array $attributes): void
    {
        $allowed = [
            'actor_type', 'actor_reference', 'status', 'lock_version', 'activated_at', 'due_at', 'completed_at',
            'assignment_revision', 'last_reassign_decision_id',
        ];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw new ImmutableDataException(self::class, 'recovery_projection_invalid');
        }
        $this->authorizedRecoveryProjection = true;
        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->authorizedRecoveryProjection = false;
        }
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(LegalWorkflowInstance::class, 'instance_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(LegalWorkflowDecision::class, 'step_id')->orderBy('id');
    }
}
