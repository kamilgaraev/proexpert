<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Enums\MarketplaceProfileStatus;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceProfilePaused;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceProfilePublished;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorDocument;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class MarketplaceProfileService
{
    public function __construct(
        private readonly FileService $fileService,
    ) {
    }

    public function getOrCreateForOrganization(int $organizationId): MarketplaceContractorProfile
    {
        $profile = MarketplaceContractorProfile::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'status' => MarketplaceProfileStatus::DRAFT,
                'availability_status' => 'hidden',
                'verification_level' => 'none',
                'is_visible_in_marketplace' => false,
                'metadata' => [],
            ]
        );

        return $this->loadProfileRelations($profile);
    }

    public function updateForOrganization(int $organizationId, array $data): MarketplaceContractorProfile
    {
        return DB::transaction(function () use ($organizationId, $data): MarketplaceContractorProfile {
            $profile = $this->getOrCreateForOrganization($organizationId);

            if ($profile->status === MarketplaceProfileStatus::BLOCKED) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.profile_blocked'));
            }

            $profileFields = array_intersect_key($data, array_flip([
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
                'metadata',
            ]));

            if ($profileFields !== []) {
                $profile->update($profileFields);
            }

            if (array_key_exists('categories', $data)) {
                $this->syncCategories($profile, $data['categories'] ?? []);
            }

            if (array_key_exists('regions', $data)) {
                $this->syncRegions($profile, $data['regions'] ?? []);
            }

            if (array_key_exists('portfolio_items', $data)) {
                $this->syncPortfolioItems($profile, $data['portfolio_items'] ?? []);
            }

            return $this->loadProfileRelations($profile->refresh());
        });
    }

    public function uploadDocumentForOrganization(
        int $organizationId,
        UploadedFile $file,
        array $data,
        ?User $actor = null
    ): MarketplaceContractorProfile {
        return DB::transaction(function () use ($organizationId, $file, $data, $actor): MarketplaceContractorProfile {
            $profile = $this->getOrCreateForOrganization($organizationId)->loadMissing('organization');
            $organization = $profile->organization;

            if (! $organization instanceof Organization) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.organization_context_missing'), 400);
            }

            $path = $this->fileService->upload(
                $file,
                "contractor-marketplace/profile-{$profile->id}/documents",
                null,
                'private',
                $organization
            );

            if ($path === false) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.document_upload_failed'), 500);
            }

            $profile->documents()->create([
                'type' => $data['type'],
                'title' => $data['title'],
                'file_path' => $path,
                'status' => 'pending',
                'metadata' => [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by_user_id' => $actor?->id,
                ],
            ]);

            return $this->loadProfileRelations($profile->refresh());
        });
    }

    public function deleteDocumentForOrganization(int $organizationId, MarketplaceContractorDocument $document): MarketplaceContractorProfile
    {
        return DB::transaction(function () use ($organizationId, $document): MarketplaceContractorProfile {
            $profile = $this->getOrCreateForOrganization($organizationId)->loadMissing('organization');

            if ((int) $document->profile_id !== (int) $profile->id) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.document_not_found'), 404);
            }

            $this->fileService->delete($document->file_path, $profile->organization);
            $document->delete();

            return $this->loadProfileRelations($profile->refresh());
        });
    }

    public function publish(int $organizationId, ?User $actor = null): MarketplaceContractorProfile
    {
        return DB::transaction(function () use ($organizationId, $actor): MarketplaceContractorProfile {
            $profile = $this->getOrCreateForOrganization($organizationId);

            if (! $this->canPublish($profile)) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.profile_publish_requirements'));
            }

            $shouldRecordPublication = $profile->status !== MarketplaceProfileStatus::ACTIVE
                || ! $profile->is_visible_in_marketplace;

            $profile->update([
                'status' => MarketplaceProfileStatus::ACTIVE,
                'is_visible_in_marketplace' => true,
                'published_at' => $profile->published_at ?? now(),
            ]);

            $profile = $this->loadProfileRelations($profile->refresh());

            if ($shouldRecordPublication) {
                event(new MarketplaceProfilePublished($profile, $actor));
            }

            return $profile;
        });
    }

    public function pause(int $organizationId, ?User $actor = null): MarketplaceContractorProfile
    {
        return DB::transaction(function () use ($organizationId, $actor): MarketplaceContractorProfile {
            $profile = $this->getOrCreateForOrganization($organizationId);

            if ($profile->status === MarketplaceProfileStatus::BLOCKED) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.profile_blocked'));
            }

            $shouldRecordPause = $profile->status !== MarketplaceProfileStatus::PAUSED
                || $profile->is_visible_in_marketplace;

            $profile->update([
                'status' => MarketplaceProfileStatus::PAUSED,
                'is_visible_in_marketplace' => false,
            ]);

            $profile = $this->loadProfileRelations($profile->refresh());

            if ($shouldRecordPause) {
                event(new MarketplaceProfilePaused($profile, $actor));
            }

            return $profile;
        });
    }

    private function canPublish(MarketplaceContractorProfile $profile): bool
    {
        $profile->loadMissing('categories');

        return filled($profile->display_name)
            && filled($profile->base_city)
            && filled($profile->availability_status)
            && $profile->availability_status !== 'hidden'
            && $profile->categories->isNotEmpty();
    }

    private function syncCategories(MarketplaceContractorProfile $profile, array $categories): void
    {
        $categoryIds = collect($categories)
            ->pluck('category_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $activeCategoryIds = MarketplaceWorkCategory::query()
            ->active()
            ->whereIn('id', $categoryIds)
            ->pluck('id')
            ->all();

        if ($categoryIds->count() !== count($activeCategoryIds)) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.category_unavailable'));
        }

        $profile->categories()->delete();

        foreach ($categories as $category) {
            $profile->categories()->create([
                'category_id' => (int) $category['category_id'],
                'is_primary' => (bool) ($category['is_primary'] ?? false),
                'experience_years' => $category['experience_years'] ?? null,
                'team_capacity' => $category['team_capacity'] ?? null,
                'min_project_budget' => $category['min_project_budget'] ?? null,
                'max_project_budget' => $category['max_project_budget'] ?? null,
            ]);
        }
    }

    private function syncRegions(MarketplaceContractorProfile $profile, array $regions): void
    {
        $profile->regions()->delete();

        foreach ($regions as $region) {
            $profile->regions()->create([
                'country' => $region['country'] ?? 'Россия',
                'region' => $region['region'] ?? null,
                'city' => $region['city'] ?? null,
                'is_primary' => (bool) ($region['is_primary'] ?? false),
            ]);
        }
    }

    private function syncPortfolioItems(MarketplaceContractorProfile $profile, array $items): void
    {
        $categoryIds = collect($items)
            ->pluck('category_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($categoryIds->isNotEmpty()) {
            $activeCategoryIds = MarketplaceWorkCategory::query()
                ->active()
                ->whereIn('id', $categoryIds)
                ->pluck('id')
                ->all();

            if ($categoryIds->count() !== count($activeCategoryIds)) {
                throw new BusinessLogicException(trans_message('contractor_marketplace.category_unavailable'));
            }
        }

        $profile->portfolioItems()->delete();

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $profile->portfolioItems()->create([
                'category_id' => $item['category_id'] ?? null,
                'title' => $title,
                'description' => $item['description'] ?? null,
                'city' => $item['city'] ?? null,
                'completed_at' => $item['completed_at'] ?? null,
                'media' => $item['media'] ?? [],
                'metadata' => $item['metadata'] ?? [],
            ]);
        }
    }

    private function loadProfileRelations(MarketplaceContractorProfile $profile): MarketplaceContractorProfile
    {
        return $profile->load([
            'organization',
            'categories.category',
            'regions',
            'portfolioItems.category',
            'documents',
        ]);
    }
}
