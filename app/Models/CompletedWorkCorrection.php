<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class CompletedWorkCorrection extends Model
{
    protected $fillable = [
        'completed_work_id',
        'organization_id',
        'project_id',
        'actor_id',
        'operation_key',
        'expected_version',
        'reason',
        'source_event_id',
        'payload_hash',
        'snapshot_before',
        'snapshot_after',
    ];

    protected $casts = [
        'snapshot_before' => 'array',
        'snapshot_after' => 'array',
    ];

    public function completedWork()
    {
        return $this->belongsTo(CompletedWork::class);
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('completed_work_correction_is_immutable');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('completed_work_correction_is_immutable');
        });
    }
}
