<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActingPolicy extends Model
{
    use HasFactory;

    public const MODE_FREE = 'free';
    public const MODE_OPERATIONAL = 'operational';
    public const MODE_STRICT = 'strict';

    protected $fillable = [
        'organization_id',
        'contract_id',
        'mode',
        'allow_manual_lines',
        'require_manual_line_reason',
        'settings',
    ];

    protected $casts = [
        'allow_manual_lines' => 'boolean',
        'require_manual_line_reason' => 'boolean',
        'settings' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
