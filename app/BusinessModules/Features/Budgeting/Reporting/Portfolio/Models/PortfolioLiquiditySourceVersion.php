<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PortfolioLiquiditySourceVersion extends Model
{
    protected $table = 'budgeting_portfolio_liquidity_source_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'effective_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('liquidity_source_version_immutable'));
        self::deleting(static fn (): never => throw new LogicException('liquidity_source_version_immutable'));
    }
}
