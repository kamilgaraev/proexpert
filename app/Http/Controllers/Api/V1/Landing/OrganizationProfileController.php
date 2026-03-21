<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Domain\Organization\ValueObjects\OrganizationProfile;
use App\Enums\OrganizationCapability;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Organization\OrganizationProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrganizationProfileController extends Controller
{
    protected OrganizationProfileService $profileService;

    public function __construct(OrganizationProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    public function getProfile(Request $request): JsonResponse
    {
        try {
            $organization = $request->user()?->currentOrganization;

            if (!$organization instanceof Organization) {
                return $this->notFoundResponse();
            }

            $profile = $this->profileService->getProfile($organization);

            return response()->json([
                'success' => true,
                'data' => $this->buildProfilePayload($organization, $profile),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to get organization profile', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve profile',
            ], 500);
        }
    }

    public function updateCapabilities(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'capabilities' => 'required|array',
            'capabilities.*' => 'string|in:' . implode(',', array_map(fn ($capability) => $capability->value, OrganizationCapability::cases())),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $organization = $request->user()?->currentOrganization;

            if (!$organization instanceof Organization) {
                return $this->notFoundResponse();
            }

            $updatedOrganization = $this->profileService->updateCapabilities(
                $organization,
                $request->input('capabilities', [])
            );
            $profile = $this->profileService->getProfile($updatedOrganization);

            return response()->json([
                'success' => true,
                'message' => 'Capabilities успешно обновлены',
                'data' => $this->buildProfileUpdatePayload($profile),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update capabilities', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update capabilities',
            ], 500);
        }
    }

    public function updateBusinessType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'primary_business_type' => 'required|string|in:' . implode(',', array_map(fn ($capability) => $capability->value, OrganizationCapability::cases())),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $organization = $request->user()?->currentOrganization;

            if (!$organization instanceof Organization) {
                return $this->notFoundResponse();
            }

            $updatedOrganization = $this->profileService->updatePrimaryBusinessType(
                $organization,
                (string) $request->input('primary_business_type')
            );
            $profile = $this->profileService->getProfile($updatedOrganization);

            return response()->json([
                'success' => true,
                'message' => 'Основной тип деятельности обновлен',
                'data' => $this->buildProfileUpdatePayload($profile),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to update business type', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update business type',
            ], 500);
        }
    }

    public function updateSpecializations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'specializations' => 'required|array',
            'specializations.*' => 'string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $organization = $request->user()?->currentOrganization;

            if (!$organization instanceof Organization) {
                return $this->notFoundResponse();
            }

            $updatedOrganization = $this->profileService->updateSpecializations(
                $organization,
                $request->input('specializations', [])
            );
            $profile = $this->profileService->getProfile($updatedOrganization);

            return response()->json([
                'success' => true,
                'message' => 'Специализации обновлены',
                'data' => $this->buildProfileUpdatePayload($profile),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update specializations', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update specializations',
            ], 500);
        }
    }

    public function updateCertifications(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'certifications' => 'required|array',
            'certifications.*' => 'string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $organization = $request->user()?->currentOrganization;

            if (!$organization instanceof Organization) {
                return $this->notFoundResponse();
            }

            $updatedOrganization = $this->profileService->updateCertifications(
                $organization,
                $request->input('certifications', [])
            );
            $profile = $this->profileService->getProfile($updatedOrganization);

            return response()->json([
                'success' => true,
                'message' => 'Сертификаты обновлены',
                'data' => $this->buildProfileUpdatePayload($profile),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update certifications', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update certifications',
            ], 500);
        }
    }

    public function completeOnboarding(Request $request): JsonResponse
    {
        try {
            $organization = $request->user()?->currentOrganization;

            if (!$organization instanceof Organization) {
                return $this->notFoundResponse();
            }

            $profile = $this->profileService->getProfile($organization);

            if ($profile->getProfileCompleteness() < 50) {
                return response()->json([
                    'success' => false,
                    'message' => 'Профиль заполнен менее чем на 50%. Пожалуйста, заполните основную информацию.',
                    'data' => [
                        'completeness' => $profile->getProfileCompleteness(),
                        'required_fields' => [
                            'capabilities' => empty($profile->getCapabilities()),
                            'business_type' => $profile->getPrimaryBusinessType() === null,
                        ],
                    ],
                ], 400);
            }

            $updatedOrganization = $this->profileService->completeOnboarding($organization);
            $updatedProfile = $this->profileService->getProfile($updatedOrganization);

            return response()->json([
                'success' => true,
                'message' => 'Onboarding успешно завершен',
                'data' => array_merge(
                    [
                        'onboarding_completed' => $updatedProfile->isOnboardingCompleted(),
                        'onboarding_completed_at' => $updatedProfile->getOnboardingCompletedAt()?->format('Y-m-d H:i:s'),
                    ],
                    $this->buildProfileUpdatePayload($updatedProfile)
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to complete onboarding', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete onboarding',
            ], 500);
        }
    }

    public function getAvailableCapabilities(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => OrganizationCapability::toArray(),
        ]);
    }

    private function buildProfilePayload(Organization $organization, OrganizationProfile $profile): array
    {
        return [
            'organization_id' => $organization->id,
            'name' => $organization->name,
            'inn' => $organization->inn ?? $organization->tax_number,
            'capabilities' => $profile->getCapabilities(),
            'primary_business_type' => $profile->getPrimaryBusinessType()?->value,
            'specializations' => $profile->getSpecializations(),
            'certifications' => $profile->getCertifications(),
            'profile_completeness' => $profile->getProfileCompleteness(),
            'onboarding_completed' => $profile->isOnboardingCompleted(),
            'onboarding_completed_at' => $profile->getOnboardingCompletedAt()?->format('Y-m-d H:i:s'),
            'recommended_modules' => $profile->getRecommendedModules(),
            'workspace_profile' => $profile->getWorkspaceProfile(),
        ];
    }

    private function buildProfileUpdatePayload(OrganizationProfile $profile): array
    {
        return [
            'primary_business_type' => $profile->getPrimaryBusinessType()?->value,
            'profile_completeness' => $profile->getProfileCompleteness(),
            'recommended_modules' => $profile->getRecommendedModules(),
            'workspace_profile' => $profile->getWorkspaceProfile(),
        ];
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Организация не найдена',
        ], 404);
    }
}
