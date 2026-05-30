<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Resources;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferWorkPackage;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceHiringOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MarketplaceHiringOffer $offer */
        $offer = $this->resource;

        return [
            'id' => $offer->id,
            'status' => $offer->status?->value,
            'role' => $offer->role,
            'title' => $offer->title,
            'message' => $offer->message,
            'project' => $this->mapProject($offer->project),
            'hiring_organization' => $this->mapOrganization($offer->hiringOrganization),
            'contractor_organization' => $this->mapOrganization($offer->contractorOrganization),
            'contractor_profile' => new MarketplaceContractorProfileResource($offer->contractorProfile),
            'work_packages' => $offer->workPackages
                ->map(fn (MarketplaceHiringOfferWorkPackage $workPackage): array => $this->mapWorkPackage($workPackage))
                ->values()
                ->all(),
            'reviews' => $offer->reviews
                ->map(fn (MarketplaceHiringOfferReview $review): array => $this->mapReview($review))
                ->values()
                ->all(),
            'starts_at' => $offer->starts_at?->toISOString(),
            'ends_at' => $offer->ends_at?->toISOString(),
            'budget_min' => $offer->budget_min,
            'budget_max' => $offer->budget_max,
            'currency' => $offer->currency,
            'expires_at' => $offer->expires_at?->toISOString(),
            'sent_at' => $offer->sent_at?->toISOString(),
            'viewed_at' => $offer->viewed_at?->toISOString(),
            'accepted_at' => $offer->accepted_at?->toISOString(),
            'declined_at' => $offer->declined_at?->toISOString(),
            'cancelled_at' => $offer->cancelled_at?->toISOString(),
            'decline_reason' => $offer->decline_reason,
            'status_reason' => $offer->status_reason,
            'created_by' => $this->mapUser($offer->createdBy),
            'responded_by' => $this->mapUser($offer->respondedBy),
            'metadata' => $offer->metadata ?? [],
            'created_at' => $offer->created_at?->toISOString(),
            'updated_at' => $offer->updated_at?->toISOString(),
        ];
    }

    private function mapProject(?Project $project): ?array
    {
        if (! $project instanceof Project) {
            return null;
        }

        return [
            'id' => $project->id,
            'name' => $project->name,
            'address' => $project->address,
            'status' => $project->status,
        ];
    }

    private function mapOrganization(?Organization $organization): ?array
    {
        if (! $organization instanceof Organization) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'tax_number' => $organization->tax_number,
            'email' => $organization->email,
            'phone' => $organization->phone,
        ];
    }

    private function mapWorkPackage(MarketplaceHiringOfferWorkPackage $workPackage): array
    {
        return [
            'id' => $workPackage->id,
            'category' => $this->mapCategory($workPackage->category),
            'title' => $workPackage->title,
            'description' => $workPackage->description,
            'quantity' => $workPackage->quantity,
            'unit' => $workPackage->unit,
            'budget_min' => $workPackage->budget_min,
            'budget_max' => $workPackage->budget_max,
            'starts_at' => $workPackage->starts_at?->toISOString(),
            'ends_at' => $workPackage->ends_at?->toISOString(),
            'metadata' => $workPackage->metadata ?? [],
        ];
    }

    private function mapCategory(?MarketplaceWorkCategory $category): ?array
    {
        if (! $category instanceof MarketplaceWorkCategory) {
            return null;
        }

        return [
            'id' => $category->id,
            'slug' => $category->slug,
            'name' => $category->name,
            'type' => $category->type?->value,
        ];
    }

    private function mapReview(MarketplaceHiringOfferReview $review): array
    {
        return [
            'id' => $review->id,
            'category' => $this->mapCategory($review->category),
            'quality_score' => $review->quality_score,
            'deadline_score' => $review->deadline_score,
            'communication_score' => $review->communication_score,
            'safety_score' => $review->safety_score,
            'financial_discipline_score' => $review->financial_discipline_score,
            'comment' => $review->comment,
            'created_by' => $this->mapUser($review->createdBy),
            'created_at' => $review->created_at?->toISOString(),
            'updated_at' => $review->updated_at?->toISOString(),
        ];
    }

    private function mapUser(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
