<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class PayrollReadinessRow extends Model
{
    protected $table = 'workforce_payroll_readiness_rows';

    protected $guarded = [];

    protected $casts = [
        'work_date' => 'immutable_date',
        'hours' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new DomainException('payroll_readiness_row_immutable'));
        self::deleting(static fn (): never => throw new DomainException('payroll_readiness_row_immutable'));
    }
}
