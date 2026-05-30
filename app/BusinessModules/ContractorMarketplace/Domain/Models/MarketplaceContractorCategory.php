<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceContractorCategory extends Model
{
    protected $table = 'marketplace_contractor_categories';

    protected $fillable = [
        'profile_id',
        'category_id',
        'is_primary',
        'experience_years',
        'team_capacity',
        'min_project_budget',
        'max_project_budget',
        'rating_score',
        'ratings_count',
        'completed_projects_count',
        'last_completed_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'experience_years' => 'integer',
        'team_capacity' => 'integer',
        'min_project_budget' => 'decimal:2',
        'max_project_budget' => 'decimal:2',
        'rating_score' => 'decimal:2',
        'ratings_count' => 'integer',
        'completed_projects_count' => 'integer',
        'last_completed_at' => 'datetime',
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
