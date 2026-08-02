<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagementPnlRecord extends Model
{
    protected $table = 'management_pnl_rows';

    protected $guarded = [];

    protected $casts = [
        'period' => 'immutable_date',
        'source_refs' => 'array',
        'revenue_minor' => 'integer',
        'direct_cost_minor' => 'integer',
        'gross_margin_minor' => 'integer',
        'operating_expense_minor' => 'integer',
        'operating_result_minor' => 'integer',
    ];
}
