<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkVolumeAcceptanceMapping extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['source_quantity' => 'decimal:6', 'revision' => 'integer', 'sealed' => 'boolean', 'created_at' => 'immutable_datetime'];

    public function allocations(): HasMany
    {
        return $this->hasMany(WorkVolumeAcceptedAllocation::class, 'mapping_id');
    }
}
