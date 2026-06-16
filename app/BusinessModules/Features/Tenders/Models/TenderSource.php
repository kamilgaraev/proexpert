<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class TenderSource extends TenderModel
{
    protected $fillable = [
        'organization_id',
        'code',
        'label',
        'source_type',
        'base_url',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenders(): HasMany
    {
        return $this->hasMany(Tender::class, 'source_id');
    }
}
