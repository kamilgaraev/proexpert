<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkVolumeAcceptedAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:6', 'line_snapshot' => 'array'];
}
