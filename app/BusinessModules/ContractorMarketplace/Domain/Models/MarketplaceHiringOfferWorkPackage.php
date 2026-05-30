<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceHiringOfferWorkPackage extends Model
{
    protected $table = 'marketplace_hiring_offer_work_packages';

    protected $fillable = [
        'offer_id',
        'category_id',
        'title',
        'description',
        'quantity',
        'unit',
        'budget_min',
        'budget_max',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'budget_min' => 'decimal:2',
        'budget_max' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceHiringOffer::class, 'offer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MarketplaceWorkCategory::class, 'category_id');
    }
}
