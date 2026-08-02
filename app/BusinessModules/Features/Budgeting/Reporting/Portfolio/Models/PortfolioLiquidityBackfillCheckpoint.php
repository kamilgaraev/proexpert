<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;

final class PortfolioLiquidityBackfillCheckpoint extends Model
{
    protected $table = 'budgeting_portfolio_liquidity_backfill_checkpoints';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'source_cursor' => 'integer',
            'source_upper_bound' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'ingestion_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
