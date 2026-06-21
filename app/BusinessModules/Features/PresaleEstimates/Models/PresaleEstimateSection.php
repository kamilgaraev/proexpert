<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PresaleEstimateSection extends PresaleEstimateModel
{
    protected $fillable = [
        'organization_id',
        'presale_estimate_id',
        'presale_estimate_version_id',
        'title',
        'description',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'metadata' => '{}',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimate::class, 'presale_estimate_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimateVersion::class, 'presale_estimate_version_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(PresaleEstimateLineItem::class, 'presale_estimate_section_id')
            ->orderBy('sort_order');
    }
}
