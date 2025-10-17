<?php

namespace App\Listeners;

use App\Events\OrganizationProfileUpdated;
use App\Events\OrganizationOnboardingCompleted;
use App\Services\Organization\OrganizationProfileService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SuggestModulesBasedOnCapabilities
{
    protected OrganizationProfileService $profileService;

    public function __construct(OrganizationProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    /**
     * Handle profile updated event
     */
    public function handleProfileUpdated(OrganizationProfileUpdated $event): void
    {
        // Обновляем рекомендации только если обновились capabilities
        if ($event->field === 'capabilities') {
            $this->updateRecommendations($event->organization->id);
        }
    }

    /**
     * Handle onboarding completed event
     */
    public function handleOnboardingCompleted(OrganizationOnboardingCompleted $event): void
    {
        $this->updateRecommendations($event->organization->id);
    }

    /**
     * Update module recommendations
     */
    private function updateRecommendations(int $organizationId): void
    {
        try {
            $organization = \App\Models\Organization::find($organizationId);
            
            if (!$organization) {
                return;
            }
            
            $profile = $this->profileService->getProfile($organization);
            $recommendedModules = $profile->getRecommendedModules();
            
            // Кэшируем рекомендации на 24 часа
            $cacheKey = "org:{$organizationId}:recommended_modules";
            Cache::put($cacheKey, $recommendedModules, now()->addDay());
            
            Log::info('[Modules] Recommendations updated', [
                'organization_id' => $organizationId,
                'recommended_modules' => $recommendedModules,
                'capabilities' => $profile->getCapabilities(),
            ]);
        } catch (\Exception $e) {
            Log::error('[Modules] Failed to update recommendations', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
