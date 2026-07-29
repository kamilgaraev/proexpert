<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ApprovedTimeEntryReportingFact extends Model
{
    protected $table = 'time_entry_approval_reporting_facts';

    protected $guarded = [];

    protected $casts = [
        'work_date' => 'immutable_date',
        'hours' => 'decimal:2',
        'approved_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new DomainException('approved_time_entry_reporting_fact_immutable'));
        self::deleting(static fn (): never => throw new DomainException('approved_time_entry_reporting_fact_immutable'));
    }
}
