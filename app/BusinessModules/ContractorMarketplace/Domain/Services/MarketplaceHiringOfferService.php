<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Enums\HiringOfferStatus;
use App\BusinessModules\ContractorMarketplace\Domain\Enums\MarketplaceProfileStatus;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferAccepted;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferCancelled;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferDeclined;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferReviewed;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferSent;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferViewed;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Enums\ContractorType;
use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;
use App\Models\User;
use App\Services\Project\ProjectContextService;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MarketplaceHiringOfferService
{
    public function __construct(
        private readonly MarketplaceSearchService $searchService,
        private readonly MarketplaceRatingService $ratingService,
        private readonly ProjectContextService $projectContextService,
        private readonly ProjectParticipantService $projectParticipantService
    ) {
    }

    public function listSent(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $this->expireOpenOffers(['hiring_organization_id' => $organizationId]);

        return $this->baseQuery()
            ->where('hiring_organization_id', $organizationId)
            ->when(isset($filters['project_id']), static fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', (string) $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function listInbox(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $this->expireOpenOffers(['contractor_organization_id' => $organizationId]);

        return $this->baseQuery()
            ->where('contractor_organization_id', $organizationId)
            ->when(isset($filters['project_id']), static fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', (string) $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function showForHiringOrganization(MarketplaceHiringOffer $offer, int $organizationId): MarketplaceHiringOffer
    {
        if ((int) $offer->hiring_organization_id !== $organizationId) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_access_denied'), 403);
        }

        $this->expireOfferIfNeeded($offer);

        return $this->freshOffer($offer);
    }

    public function showForContractorOrganization(MarketplaceHiringOffer $offer, int $organizationId): MarketplaceHiringOffer
    {
        if ((int) $offer->contractor_organization_id !== $organizationId) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_access_denied'), 403);
        }

        $this->expireOfferIfNeeded($offer);

        return $this->freshOffer($offer);
    }

    public function createOffer(int $hiringOrganizationId, User $actor, array $payload): MarketplaceHiringOffer
    {
        return DB::transaction(function () use ($hiringOrganizationId, $actor, $payload): MarketplaceHiringOffer {
            [$project, $hiringOrganization] = $this->resolveProjectWithHiringAccess((int) $payload['project_id'], $hiringOrganizationId);
            $profile = $this->resolveVisibleProfile((int) $payload['contractor_profile_id']);
            $contractorOrganization = $profile->organization;

            if (! $contractorOrganization instanceof Organization) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.contractor_profile_not_found'), 404);
            }

            if ((int) $contractorOrganization->id === $hiringOrganizationId) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_self_hire_forbidden'), 422);
            }

            if (! in_array((int) $contractorOrganization->id, $this->searchService->networkOrganizationIds($hiringOrganizationId), true)) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.contractor_not_in_network'), 403);
            }

            $role = $this->resolveProjectRole((string) $payload['role']);
            $this->projectParticipantService->assertCanAssumeRole($contractorOrganization, $role);
            $this->assertContractorIsNotProjectParticipant($project, (int) $contractorOrganization->id);
            $this->assertNoActiveOffer($project, $hiringOrganizationId, (int) $contractorOrganization->id);
            $this->assertWorkPackagesMatchProfile($profile, (array) $payload['work_packages']);

            $now = now();
            $offer = MarketplaceHiringOffer::query()->create([
                'project_id' => $project->id,
                'hiring_organization_id' => $hiringOrganization->id,
                'contractor_organization_id' => $contractorOrganization->id,
                'contractor_profile_id' => $profile->id,
                'created_by_user_id' => $actor->id,
                'status' => HiringOfferStatus::SENT->value,
                'role' => $role->value,
                'title' => $payload['title'],
                'message' => $payload['message'] ?? null,
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'budget_min' => $payload['budget_min'] ?? null,
                'budget_max' => $payload['budget_max'] ?? null,
                'currency' => strtoupper((string) ($payload['currency'] ?? 'RUB')),
                'expires_at' => $payload['expires_at'] ?? $now->copy()->addDays(14),
                'sent_at' => $now,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            foreach ((array) $payload['work_packages'] as $workPackage) {
                $offer->workPackages()->create([
                    'category_id' => (int) $workPackage['category_id'],
                    'title' => $workPackage['title'],
                    'description' => $workPackage['description'] ?? null,
                    'quantity' => $workPackage['quantity'] ?? null,
                    'unit' => $workPackage['unit'] ?? null,
                    'budget_min' => $workPackage['budget_min'] ?? null,
                    'budget_max' => $workPackage['budget_max'] ?? null,
                    'starts_at' => $workPackage['starts_at'] ?? null,
                    'ends_at' => $workPackage['ends_at'] ?? null,
                    'metadata' => $workPackage['metadata'] ?? [],
                ]);
            }

            $offer = $this->freshOffer($offer);
            event(new MarketplaceHiringOfferSent($offer, $actor));

            return $offer;
        });
    }

    public function markViewed(MarketplaceHiringOffer $offer, int $contractorOrganizationId, User $actor): MarketplaceHiringOffer
    {
        return DB::transaction(function () use ($offer, $contractorOrganizationId, $actor): MarketplaceHiringOffer {
            $lockedOffer = $this->lockOffer($offer);
            $this->assertContractorAccess($lockedOffer, $contractorOrganizationId);
            $lockedOffer = $this->expireOfferIfNeeded($lockedOffer);
            $shouldRecordView = false;

            if ($lockedOffer->status === HiringOfferStatus::SENT) {
                $lockedOffer->update([
                    'status' => HiringOfferStatus::VIEWED->value,
                    'viewed_at' => now(),
                ]);
                $shouldRecordView = true;
            }

            $lockedOffer = $this->freshOffer($lockedOffer);

            if ($shouldRecordView) {
                event(new MarketplaceHiringOfferViewed($lockedOffer, $actor));
            }

            return $lockedOffer;
        });
    }

    public function accept(MarketplaceHiringOffer $offer, int $contractorOrganizationId, User $actor): MarketplaceHiringOffer
    {
        return DB::transaction(function () use ($offer, $contractorOrganizationId, $actor): MarketplaceHiringOffer {
            $lockedOffer = $this->lockOffer($offer);
            $this->assertContractorAccess($lockedOffer, $contractorOrganizationId);
            $lockedOffer = $this->expireOfferIfNeeded($lockedOffer);

            if ($lockedOffer->status === HiringOfferStatus::ACCEPTED) {
                return $this->freshOffer($lockedOffer);
            }

            if (! $lockedOffer->isOpen()) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_unavailable'), 409);
            }

            $project = Project::query()->useWritePdo()->find($lockedOffer->project_id);

            if (! $project instanceof Project) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_project_not_found'), 404);
            }

            $role = $this->resolveProjectRole($lockedOffer->role);
            $this->attachOrganizationToProject($project, $contractorOrganizationId, $role, $actor);
            $this->ensureCounterpartyExists((int) $lockedOffer->hiring_organization_id, $contractorOrganizationId);

            $lockedOffer->update([
                'status' => HiringOfferStatus::ACCEPTED->value,
                'responded_by_user_id' => $actor->id,
                'viewed_at' => $lockedOffer->viewed_at ?? now(),
                'accepted_at' => now(),
                'status_reason' => null,
            ]);

            $lockedOffer = $this->freshOffer($lockedOffer);
            event(new MarketplaceHiringOfferAccepted($lockedOffer, $actor));

            return $lockedOffer;
        });
    }

    public function decline(
        MarketplaceHiringOffer $offer,
        int $contractorOrganizationId,
        User $actor,
        ?string $reason = null
    ): MarketplaceHiringOffer {
        return DB::transaction(function () use ($offer, $contractorOrganizationId, $actor, $reason): MarketplaceHiringOffer {
            $lockedOffer = $this->lockOffer($offer);
            $this->assertContractorAccess($lockedOffer, $contractorOrganizationId);
            $lockedOffer = $this->expireOfferIfNeeded($lockedOffer);

            if ($lockedOffer->status === HiringOfferStatus::DECLINED) {
                return $this->freshOffer($lockedOffer);
            }

            if (! $lockedOffer->isOpen()) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_unavailable'), 409);
            }

            $lockedOffer->update([
                'status' => HiringOfferStatus::DECLINED->value,
                'responded_by_user_id' => $actor->id,
                'viewed_at' => $lockedOffer->viewed_at ?? now(),
                'declined_at' => now(),
                'decline_reason' => $reason,
                'status_reason' => 'declined',
            ]);

            $lockedOffer = $this->freshOffer($lockedOffer);
            event(new MarketplaceHiringOfferDeclined($lockedOffer, $actor));

            return $lockedOffer;
        });
    }

    public function cancel(MarketplaceHiringOffer $offer, int $hiringOrganizationId, User $actor, ?string $reason = null): MarketplaceHiringOffer
    {
        return DB::transaction(function () use ($offer, $hiringOrganizationId, $actor, $reason): MarketplaceHiringOffer {
            $lockedOffer = $this->lockOffer($offer);
            $this->assertHiringAccess($lockedOffer, $hiringOrganizationId);
            $lockedOffer = $this->expireOfferIfNeeded($lockedOffer);

            if ($lockedOffer->status === HiringOfferStatus::CANCELLED) {
                return $this->freshOffer($lockedOffer);
            }

            if ($lockedOffer->status === HiringOfferStatus::ACCEPTED) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_cancel_accepted'), 409);
            }

            if (! $lockedOffer->isOpen()) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_unavailable'), 409);
            }

            $lockedOffer->update([
                'status' => HiringOfferStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'status_reason' => $reason ?: 'cancelled',
            ]);

            $lockedOffer = $this->freshOffer($lockedOffer);
            event(new MarketplaceHiringOfferCancelled($lockedOffer, $actor));

            return $lockedOffer;
        });
    }

    public function review(
        MarketplaceHiringOffer $offer,
        int $hiringOrganizationId,
        User $actor,
        array $payload
    ): MarketplaceHiringOffer {
        return DB::transaction(function () use ($offer, $hiringOrganizationId, $actor, $payload): MarketplaceHiringOffer {
            $lockedOffer = $this->lockOffer($offer);
            $this->assertHiringAccess($lockedOffer, $hiringOrganizationId);
            $lockedOffer = $this->expireOfferIfNeeded($lockedOffer);

            if ($lockedOffer->status !== HiringOfferStatus::ACCEPTED) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_review_requires_accepted'), 409);
            }

            $lockedOffer->loadMissing(['workPackages', 'contractorProfile.categories']);
            $allowedCategoryIds = $lockedOffer->workPackages
                ->pluck('category_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $reviewedCategoryIds = [];

            foreach ((array) $payload['reviews'] as $review) {
                $categoryId = (int) $review['category_id'];

                if (! in_array($categoryId, $allowedCategoryIds, true)) {
                    throw new BusinessLogicException(trans_message('contractor_marketplace.offer_review_category_forbidden'), 422);
                }

                MarketplaceHiringOfferReview::query()->updateOrCreate(
                    [
                        'offer_id' => $lockedOffer->id,
                        'category_id' => $categoryId,
                        'reviewer_organization_id' => $hiringOrganizationId,
                    ],
                    [
                        'project_id' => $lockedOffer->project_id,
                        'contractor_organization_id' => $lockedOffer->contractor_organization_id,
                        'contractor_profile_id' => $lockedOffer->contractor_profile_id,
                        'created_by_user_id' => $actor->id,
                        'quality_score' => $review['quality_score'],
                        'deadline_score' => $review['deadline_score'],
                        'communication_score' => $review['communication_score'],
                        'safety_score' => $review['safety_score'] ?? null,
                        'financial_discipline_score' => $review['financial_discipline_score'] ?? null,
                        'comment' => $review['comment'] ?? null,
                        'metadata' => $review['metadata'] ?? [],
                    ]
                );

                $reviewedCategoryIds[] = $categoryId;
            }

            foreach (array_unique($reviewedCategoryIds) as $categoryId) {
                $this->ratingService->recalculateFromReviews((int) $lockedOffer->contractor_profile_id, (int) $categoryId);
            }

            $lockedOffer = $this->freshOffer($lockedOffer);
            event(new MarketplaceHiringOfferReviewed($lockedOffer, $actor));

            return $lockedOffer;
        });
    }

    /**
     * @return array{0: Project, 1: Organization}
     */
    private function resolveProjectWithHiringAccess(int $projectId, int $hiringOrganizationId): array
    {
        $project = Project::query()->useWritePdo()->find($projectId);

        if (! $project instanceof Project) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_project_not_found'), 404);
        }

        $organization = Organization::query()->useWritePdo()->find($hiringOrganizationId);

        if (! $organization instanceof Organization) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.organization_context_missing'), 404);
        }

        if (! $this->projectContextService->canOrganizationAccessProject($project, $organization)) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_project_forbidden'), 403);
        }

        $context = $this->projectContextService->getContext($project, $organization);

        if (! $context->roleConfig->canInviteParticipants) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_project_invite_forbidden'), 403);
        }

        return [$project, $organization];
    }

    private function resolveVisibleProfile(int $profileId): MarketplaceContractorProfile
    {
        $profile = MarketplaceContractorProfile::query()
            ->with(['organization', 'categories'])
            ->whereKey($profileId)
            ->where('status', MarketplaceProfileStatus::ACTIVE->value)
            ->where('is_visible_in_marketplace', true)
            ->first();

        if (! $profile instanceof MarketplaceContractorProfile) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.contractor_profile_not_found'), 404);
        }

        return $profile;
    }

    private function resolveProjectRole(string $role): ProjectOrganizationRole
    {
        $projectRole = ProjectOrganizationRole::tryFrom($role);

        if (! $projectRole instanceof ProjectOrganizationRole) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_role_invalid'), 422);
        }

        return $projectRole;
    }

    private function assertContractorIsNotProjectParticipant(Project $project, int $contractorOrganizationId): void
    {
        $exists = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('organization_id', $contractorOrganizationId)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_project_participant_exists'), 409);
        }
    }

    private function assertNoActiveOffer(Project $project, int $hiringOrganizationId, int $contractorOrganizationId): void
    {
        $exists = MarketplaceHiringOffer::query()
            ->where('project_id', $project->id)
            ->where('hiring_organization_id', $hiringOrganizationId)
            ->where('contractor_organization_id', $contractorOrganizationId)
            ->open()
            ->exists();

        if ($exists) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_already_active'), 409);
        }
    }

    private function assertWorkPackagesMatchProfile(MarketplaceContractorProfile $profile, array $workPackages): void
    {
        if ($workPackages === []) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_work_packages_required'), 422);
        }

        $categoryIds = collect($workPackages)
            ->map(static fn (array $workPackage): int => (int) $workPackage['category_id'])
            ->unique()
            ->values();

        $activeCategories = MarketplaceWorkCategory::query()
            ->whereIn('id', $categoryIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $profileCategoryIds = $profile->categories()
            ->whereIn('category_id', $categoryIds)
            ->pluck('category_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        foreach ($categoryIds as $categoryId) {
            if (! in_array($categoryId, $activeCategories, true) || ! in_array($categoryId, $profileCategoryIds, true)) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.offer_category_unavailable'), 422);
            }
        }
    }

    private function attachOrganizationToProject(
        Project $project,
        int $contractorOrganizationId,
        ProjectOrganizationRole $role,
        User $actor
    ): void {
        $activeParticipant = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('organization_id', $contractorOrganizationId)
            ->where('is_active', true)
            ->first();

        if ($activeParticipant instanceof ProjectOrganization) {
            $currentRoleValue = $activeParticipant->getRawOriginal('role_new') ?: $activeParticipant->getRawOriginal('role');

            if ($currentRoleValue !== $role->value) {
                $this->projectParticipantService->updateRole($project, $contractorOrganizationId, $role, $actor);
            }

            return;
        }

        $this->projectParticipantService->attach($project, $contractorOrganizationId, $role, $actor);
    }

    private function ensureCounterpartyExists(int $hiringOrganizationId, int $contractorOrganizationId): void
    {
        $exists = Contractor::query()
            ->where('organization_id', $hiringOrganizationId)
            ->where('source_organization_id', $contractorOrganizationId)
            ->exists();

        if ($exists) {
            return;
        }

        $sourceOrganization = Organization::query()->find($contractorOrganizationId);

        if (! $sourceOrganization instanceof Organization) {
            return;
        }

        Contractor::query()->create([
            'organization_id' => $hiringOrganizationId,
            'source_organization_id' => $contractorOrganizationId,
            'name' => $sourceOrganization->name,
            'inn' => $sourceOrganization->tax_number,
            'legal_address' => $sourceOrganization->address,
            'phone' => $sourceOrganization->phone,
            'email' => $sourceOrganization->email,
            'contractor_type' => ContractorType::INVITED_ORGANIZATION->value,
            'connected_at' => now(),
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn'],
                'sync_interval_hours' => 24,
            ],
        ]);
    }

    private function lockOffer(MarketplaceHiringOffer $offer): MarketplaceHiringOffer
    {
        $lockedOffer = MarketplaceHiringOffer::query()
            ->whereKey($offer->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedOffer instanceof MarketplaceHiringOffer) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_not_found'), 404);
        }

        return $lockedOffer;
    }

    private function assertContractorAccess(MarketplaceHiringOffer $offer, int $organizationId): void
    {
        if ((int) $offer->contractor_organization_id !== $organizationId) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_access_denied'), 403);
        }
    }

    private function assertHiringAccess(MarketplaceHiringOffer $offer, int $organizationId): void
    {
        if ((int) $offer->hiring_organization_id !== $organizationId) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_access_denied'), 403);
        }
    }

    private function expireOpenOffers(array $where): void
    {
        MarketplaceHiringOffer::query()
            ->open()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where($where)
            ->update([
                'status' => HiringOfferStatus::EXPIRED->value,
                'status_reason' => 'expired',
            ]);
    }

    private function expireOfferIfNeeded(MarketplaceHiringOffer $offer): MarketplaceHiringOffer
    {
        if (
            $offer->isOpen()
            && $offer->expires_at !== null
            && $offer->expires_at->isPast()
        ) {
            $offer->update([
                'status' => HiringOfferStatus::EXPIRED->value,
                'status_reason' => 'expired',
            ]);
            $offer->refresh();
        }

        return $offer;
    }

    private function freshOffer(MarketplaceHiringOffer $offer): MarketplaceHiringOffer
    {
        $freshOffer = $offer->fresh([
            'project:id,name,address,status',
            'hiringOrganization:id,name,tax_number,email,phone',
            'contractorOrganization:id,name,tax_number,email,phone',
            'contractorProfile.categories.category',
            'contractorProfile.ratings.category',
            'workPackages.category',
            'reviews.category',
            'reviews.createdBy:id,name,email',
            'createdBy:id,name,email',
            'respondedBy:id,name,email',
        ]);

        return $freshOffer instanceof MarketplaceHiringOffer ? $freshOffer : $offer;
    }

    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return MarketplaceHiringOffer::query()
            ->with([
                'project:id,name,address,status',
                'hiringOrganization:id,name,tax_number,email,phone',
                'contractorOrganization:id,name,tax_number,email,phone',
                'contractorProfile.categories.category',
                'contractorProfile.ratings.category',
                'workPackages.category',
                'reviews.category',
                'reviews.createdBy:id,name,email',
                'createdBy:id,name,email',
                'respondedBy:id,name,email',
            ]);
    }
}
