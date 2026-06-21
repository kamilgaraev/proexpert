<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PresaleEstimateVersion extends PresaleEstimateModel
{
    protected $fillable = [
        'organization_id',
        'presale_estimate_id',
        'version_number',
        'status',
        'title',
        'sections_snapshot',
        'totals_snapshot',
        'content_hash',
        'created_by_user_id',
        'accepted_at',
        'locked_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'sections_snapshot' => 'array',
        'totals_snapshot' => 'array',
        'accepted_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
        'sections_snapshot' => '[]',
        'totals_snapshot' => '{}',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimate::class, 'presale_estimate_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PresaleEstimateSection::class, 'presale_estimate_version_id')
            ->orderBy('sort_order');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(PresaleEstimateLineItem::class, 'presale_estimate_version_id')
            ->orderBy('sort_order');
    }
}
