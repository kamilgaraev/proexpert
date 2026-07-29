<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models;

use Illuminate\Database\Eloquent\Model;

final class ProjectFinanceRow extends Model
{
    protected $table = 'budgeting_project_finance_rows';

    protected $guarded = [];

    protected $casts = [
        'period' => 'date',
        'margin_percent' => 'decimal:8',
        'spi' => 'decimal:8',
        'cpi' => 'decimal:8',
        'source_refs' => 'array',
    ];
}
