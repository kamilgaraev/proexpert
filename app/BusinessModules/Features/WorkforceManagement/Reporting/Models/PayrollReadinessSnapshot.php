<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PayrollReadinessSnapshot extends Model
{
    protected $table = 'workforce_payroll_readiness_snapshots';

    protected $guarded = [];

    protected $casts = [
        'period_from' => 'immutable_date',
        'period_to' => 'immutable_date',
        'locked_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new DomainException('payroll_readiness_snapshot_immutable'));
        self::deleting(static fn (): never => throw new DomainException('payroll_readiness_snapshot_immutable'));
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PayrollReadinessRow::class, 'snapshot_id');
    }
}
