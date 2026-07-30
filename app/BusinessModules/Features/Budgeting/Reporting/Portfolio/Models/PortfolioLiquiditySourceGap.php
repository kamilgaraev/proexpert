<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;

final class PortfolioLiquiditySourceGap extends Model
{
    protected $table = 'budgeting_portfolio_liquidity_source_gaps';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'source_id' => 'string',
            'missing_fields' => 'array',
            'observed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
