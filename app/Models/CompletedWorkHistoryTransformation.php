<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class CompletedWorkHistoryTransformation extends Model
{
    protected $fillable = [
        'completed_work_id',
        'organization_id',
        'project_id',
        'actor_id',
        'source',
        'rule',
        'outcome',
        'fields_mutated',
        'operation_key',
        'payload_hash',
        'reason',
        'original_quantity',
        'original_completed_quantity',
        'canonical_quantity',
        'protocol',
    ];

    protected $casts = [
        'fields_mutated' => 'boolean',
        'original_quantity' => 'decimal:4',
        'original_completed_quantity' => 'decimal:4',
        'canonical_quantity' => 'decimal:4',
        'protocol' => 'array',
    ];

    public function completedWork()
    {
        return $this->belongsTo(CompletedWork::class);
    }

    public function toReport(): array
    {
        return [
            'id' => (int) $this->id,
            'completed_work_id' => (int) $this->completed_work_id,
            'source' => $this->source,
            'rule' => $this->rule,
            'outcome' => $this->outcome,
            'fields_mutated' => (bool) $this->fields_mutated,
            'operation_key' => $this->operation_key,
            'reason' => $this->reason,
            'actor_id' => $this->actor_id !== null ? (int) $this->actor_id : null,
            'original_quantity' => $this->original_quantity,
            'original_completed_quantity' => $this->original_completed_quantity,
            'canonical_quantity' => $this->canonical_quantity,
            'protocol' => $this->protocol,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new RuntimeException('completed_work_history_transformation_is_immutable');
        });
        self::deleting(static function (): void {
            throw new RuntimeException('completed_work_history_transformation_is_immutable');
        });
    }
}
