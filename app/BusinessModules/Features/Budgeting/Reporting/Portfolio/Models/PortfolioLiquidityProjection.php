<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PortfolioLiquidityProjection extends Model
{
    protected $table = 'budgeting_portfolio_liquidity_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'duplicate_source_count' => 'integer',
            'forecast_date' => 'immutable_date',
            'source_refs' => 'array',
            'quality_gaps' => 'array',
            'warnings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('report_row_immutable'));
        self::deleting(static fn (): never => throw new LogicException('report_row_immutable'));
    }
}
