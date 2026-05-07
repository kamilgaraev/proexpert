<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateImportError extends Model
{
    protected $table = 'estimate_import_errors';

    protected $fillable = [
        'dataset_version_id',
        'source_file',
        'row_number',
        'node_path',
        'severity',
        'message',
        'raw_fragment',
    ];

    protected $casts = [
        'dataset_version_id' => 'integer',
        'row_number' => 'integer',
        'raw_fragment' => 'array',
    ];

    public function datasetVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateDatasetVersion::class, 'dataset_version_id');
    }
}
