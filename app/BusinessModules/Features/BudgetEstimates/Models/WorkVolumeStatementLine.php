<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkVolumeStatementLine extends Model
{
    use HasFactory;

    protected $table = 'work_volume_statement_lines';

    protected $fillable = [
        'statement_id', 'line_key', 'name', 'unit_code', 'quantity', 'place',
        'measurement_formula', 'basis_revision', 'estimate_item_id', 'metadata',
    ];

    protected $casts = ['quantity' => 'decimal:6', 'place' => 'array', 'metadata' => 'array'];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(WorkVolumeStatement::class, 'statement_id');
    }
}
