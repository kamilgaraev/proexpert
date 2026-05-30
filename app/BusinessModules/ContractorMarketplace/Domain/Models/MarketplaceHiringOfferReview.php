<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceHiringOfferReview extends Model
{
    protected $table = 'marketplace_hiring_offer_reviews';

    protected $fillable = [
        'offer_id',
        'project_id',
        'reviewer_organization_id',
        'contractor_organization_id',
        'contractor_profile_id',
        'category_id',
        'created_by_user_id',
        'quality_score',
        'deadline_score',
        'communication_score',
        'safety_score',
        'financial_discipline_score',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'quality_score' => 'decimal:2',
        'deadline_score' => 'decimal:2',
        'communication_score' => 'decimal:2',
        'safety_score' => 'decimal:2',
        'financial_discipline_score' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceHiringOffer::class, 'offer_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reviewerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'reviewer_organization_id');
    }

    public function contractorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'contractor_organization_id');
    }

    public function contractorProfile(): BelongsTo
    {
        return $this->belongsTo(MarketplaceContractorProfile::class, 'contractor_profile_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MarketplaceWorkCategory::class, 'category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
