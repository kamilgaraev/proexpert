<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceContractorRating extends Model
{
    protected $table = 'marketplace_contractor_ratings';

    protected $fillable = [
        'profile_id',
        'category_id',
        'score',
        'quality_score',
        'deadline_score',
        'communication_score',
        'safety_score',
        'financial_discipline_score',
        'reviews_count',
        'completed_offers_count',
        'repeat_hires_count',
        'last_recalculated_at',
        'source_snapshot',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'quality_score' => 'decimal:2',
        'deadline_score' => 'decimal:2',
        'communication_score' => 'decimal:2',
        'safety_score' => 'decimal:2',
        'financial_discipline_score' => 'decimal:2',
        'reviews_count' => 'integer',
        'completed_offers_count' => 'integer',
        'repeat_hires_count' => 'integer',
        'last_recalculated_at' => 'datetime',
        'source_snapshot' => 'array',
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
