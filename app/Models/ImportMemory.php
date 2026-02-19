<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMemory extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'file_format',
        'signature',
        'original_headers',
        'column_mapping',
        'section_hints',
        'header_row',
        'success_count',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'original_headers' => 'array',
        'column_mapping'   => 'array',
        'section_hints'    => 'array',
        'success_count'    => 'integer',
        'usage_count'      => 'integer',
        'last_used_at'     => 'datetime',
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
