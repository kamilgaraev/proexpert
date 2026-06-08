<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetImportRow extends Model
{
    protected $fillable = [
        'budget_import_batch_id',
        'row_number',
        'raw_payload',
        'normalized_payload',
        'validation_status',
        'validation_errors',
        'validation_warnings',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
        'validation_errors' => 'array',
        'validation_warnings' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BudgetImportBatch::class, 'budget_import_batch_id');
    }
}
