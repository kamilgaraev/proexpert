<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProjectFinanceSnapshot extends Model
{
    protected $table = 'budgeting_project_finance_snapshots';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'as_of' => 'immutable_datetime',
        'totals' => 'array',
        'source_refs' => 'array',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ProjectFinanceRow::class, 'snapshot_id');
    }
}
