<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalWorkflowStep extends Model
{
    protected $fillable = [
        'instance_id', 'organization_id', 'step_key', 'label', 'sequence', 'parallel_group',
        'required', 'policy_key', 'actor_type', 'actor_reference', 'status', 'lock_version',
        'due_in_hours', 'deadline_at', 'activated_at', 'due_at', 'completed_at',
    ];

    protected $casts = [
        'sequence' => 'integer', 'required' => 'boolean', 'lock_version' => 'integer', 'due_in_hours' => 'integer',
        'deadline_at' => 'datetime', 'activated_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime',
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
        });
        self::deleting(static fn (self $step): never => throw new ImmutableDataException(self::class, 'delete'));
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
