<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DesignNormativeSource extends Model
{
    protected $fillable = [
        'code',
        'title',
        'version',
        'effective_from',
        'effective_to',
        'source_url',
        'status',
        'metadata',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'active',
        'metadata' => '{}',
    ];

    public function templates(): HasMany
    {
        return $this->hasMany(DesignDocumentTemplate::class, 'normative_source_id');
    }
}
