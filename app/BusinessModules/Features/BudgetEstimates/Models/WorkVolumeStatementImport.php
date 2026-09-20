<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkVolumeStatementImport extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'raw_rows' => 'array', 'preview_rows' => 'array', 'preview_errors' => 'array',
        'options' => 'array', 'sheet_names' => 'array', 'preview_version' => 'integer',
    ];
}
