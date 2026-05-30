<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use App\BusinessModules\ContractorMarketplace\Domain\Enums\MarketplaceProfileStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceContractorProfile extends Model
{
    protected $table = 'marketplace_contractor_profiles';

    protected $fillable = [
        'organization_id',
        'status',
        'display_name',
        'short_description',
        'description',
        'team_size_min',
        'team_size_max',
        'years_on_market',
        'base_city',
        'service_radius_km',
        'availability_status',
        'available_from',
        'verification_level',
        'is_visible_in_marketplace',
        'published_at',
        'metadata',
    ];

    protected $casts = [
        'status' => MarketplaceProfileStatus::class,
        'team_size_min' => 'integer',
        'team_size_max' => 'integer',
        'years_on_market' => 'integer',
        'service_radius_km' => 'integer',
        'available_from' => 'datetime',
        'is_visible_in_marketplace' => 'boolean',
        'published_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(MarketplaceContractorCategory::class, 'profile_id');
    }

    public function regions(): HasMany
    {
        return $this->hasMany(MarketplaceContractorRegion::class, 'profile_id');
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(MarketplaceContractorPortfolioItem::class, 'profile_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MarketplaceContractorDocument::class, 'profile_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(MarketplaceContractorRating::class, 'profile_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', MarketplaceProfileStatus::ACTIVE->value)
            ->where('is_visible_in_marketplace', true);
    }
}
