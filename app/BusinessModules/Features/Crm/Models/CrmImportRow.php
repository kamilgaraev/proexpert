<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CrmImportRow extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_values',
        'normalized_values',
        'decision',
        'status',
        'validation_errors',
        'validation_warnings',
        'duplicate_candidates',
        'created_entity_id',
    ];

    protected $casts = [
        'raw_values' => 'array',
        'normalized_values' => 'array',
        'validation_errors' => 'array',
        'validation_warnings' => 'array',
        'duplicate_candidates' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CrmImportBatch::class, 'batch_id');
    }
}
