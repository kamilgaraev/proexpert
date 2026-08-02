<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ProjectPortfolioHealthProjection extends Model
{
    protected $table = 'budgeting_project_portfolio_health_rows';
    protected $guarded = [];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'risk_rank' => 'integer',
            'as_of' => 'immutable_date',
            'source_refs' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('report_row_immutable'));
        static::deleting(static fn (): never => throw new LogicException('report_row_immutable'));
    }
}
