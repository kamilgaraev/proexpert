<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models;

use Illuminate\Database\Eloquent\Model;

final class TimekeepingPeriod extends Model
{
    protected $table = 'workforce_timekeeping_periods';

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'rule_snapshot' => 'array', 'approval' => 'array', 'archive_metadata' => 'array', 'revision' => 'integer', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(static function (self $period): void {
            if ($period->getOriginal('status') === 'closed') {
                throw new \DomainException(trans_message('workforce.errors.timekeeping_closed'));
            }
            if ($period->getOriginal('status') === 'approved' && $period->isDirty(['snapshot', 'rule_snapshot', 'revision', 'snapshot_sha256', 'approval'])) {
                throw new \DomainException(trans_message('workforce.errors.timekeeping_immutable'));
            }
        });
        self::deleting(static function (): void {
            throw new \DomainException(trans_message('workforce.errors.timekeeping_immutable'));
        });
    }
}
