<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class HoldingPerformanceRow extends Model
{
    protected $table = 'holding_performance_rows';
    protected $guarded = [];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'contributor_organization_id' => 'integer',
            'project_id' => 'integer',
            'contracted_minor' => 'integer',
            'accepted_accrual_minor' => 'integer',
            'cash_minor' => 'integer',
            'period_start' => 'immutable_date',
            'source_refs' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('report_row_immutable'));
        static::deleting(static fn (): never => throw new LogicException('report_row_immutable'));
    }
}
