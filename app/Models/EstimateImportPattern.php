<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateImportPattern extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'rows_before_header',
        'header_row',
        'total_rows',
        'columns_count',
        'header_keywords',
        'has_merged_cells',
        'is_multiline_header',
        'file_structure',
        'user_selected_row',
        'user_corrections',
        'auto_detection_confidence',
        'was_correct',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'header_keywords' => 'array',
        'file_structure' => 'array',
        'user_corrections' => 'array',
        'has_merged_cells' => 'boolean',
        'is_multiline_header' => 'boolean',
        'was_correct' => 'boolean',
        'auto_detection_confidence' => 'float',
        'last_used_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

