<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class BudgetingPortfolioSnapshot extends Model
{
    protected $table = 'budgeting_portfolio_report_snapshots';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'watermarks' => 'array',
            'source_refs' => 'array',
            'as_of' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'stale_at' => 'immutable_datetime',
            'row_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('report_snapshot_immutable'));
        static::deleting(static fn (): never => throw new LogicException('report_snapshot_immutable'));
    }
}
