<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceContractorPortfolioItem extends Model
{
    protected $table = 'marketplace_contractor_portfolio_items';

    protected $fillable = [
        'profile_id',
        'category_id',
        'title',
        'description',
        'city',
        'completed_at',
        'media',
        'metadata',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'media' => 'array',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketplaceContractorProfile::class, 'profile_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MarketplaceWorkCategory::class, 'category_id');
    }
}
