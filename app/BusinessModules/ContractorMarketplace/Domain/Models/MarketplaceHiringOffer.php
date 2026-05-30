<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use App\BusinessModules\ContractorMarketplace\Domain\Enums\HiringOfferStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceHiringOffer extends Model
{
    protected $table = 'marketplace_hiring_offers';

    protected $fillable = [
        'project_id',
        'hiring_organization_id',
        'contractor_organization_id',
        'contractor_profile_id',
        'created_by_user_id',
        'responded_by_user_id',
        'status',
        'role',
        'title',
        'message',
        'starts_at',
        'ends_at',
        'budget_min',
        'budget_max',
        'currency',
        'expires_at',
        'sent_at',
        'viewed_at',
        'accepted_at',
        'declined_at',
        'cancelled_at',
        'decline_reason',
        'status_reason',
        'metadata',
    ];

    protected $casts = [
        'status' => HiringOfferStatus::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'budget_min' => 'decimal:2',
        'budget_max' => 'decimal:2',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function hiringOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'hiring_organization_id');
    }

    public function contractorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'contractor_organization_id');
    }

    public function contractorProfile(): BelongsTo
    {
        return $this->belongsTo(MarketplaceContractorProfile::class, 'contractor_profile_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    public function workPackages(): HasMany
    {
        return $this->hasMany(MarketplaceHiringOfferWorkPackage::class, 'offer_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MarketplaceHiringOfferReview::class, 'offer_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            HiringOfferStatus::SENT->value,
            HiringOfferStatus::VIEWED->value,
        ]);
    }

    public function isOpen(): bool
    {
        return $this->status instanceof HiringOfferStatus && $this->status->isOpen();
    }
}
