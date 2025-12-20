<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Organization\OrganizationProfileService;
use App\Enums\OrganizationCapability;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class OrganizationProfileController extends Controller
{
    protected OrganizationProfileService $profileService;

    public function __construct(OrganizationProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    /**
     * Получить текущий профиль организации
     * 
     * GET /api/v1/landing/organization/profile
     */
    public function getProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $profile = $this->profileService->getProfile($organization);
            
            return response()->json([
                'success' => true,
                'data' => [
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
                ],
            ]);
        } catch (\Exception $e) {
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

    /**
     * Обновить capabilities организации
     * 
     * PUT /api/v1/landing/organization/profile/capabilities
     */
    public function updateCapabilities(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'capabilities' => 'required|array',
            'capabilities.*' => 'string|in:' . implode(',', array_map(fn($c) => $c->value, OrganizationCapability::cases())),
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $updatedOrg = $this->profileService->updateCapabilities(
                $organization,
                $request->input('capabilities')
            );
            
            $profile = $this->profileService->getProfile($updatedOrg);
            
            return response()->json([
                'success' => true,
                'message' => 'Capabilities успешно обновлены',
                'data' => [
                    'profile_completeness' => $profile->getProfileCompleteness(),
                    'recommended_modules' => $profile->getRecommendedModules(),
                ],
            ]);
        } catch (\Exception $e) {
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

    /**
     * Обновить основной тип деятельности
     * 
     * PUT /api/v1/landing/organization/profile/business-type
     */
    public function updateBusinessType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'primary_business_type' => 'required|string|in:' . implode(',', array_map(fn($c) => $c->value, OrganizationCapability::cases())),
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $updatedOrg = $this->profileService->updatePrimaryBusinessType(
                $organization,
                $request->input('primary_business_type')
            );
            
            $profile = $this->profileService->getProfile($updatedOrg);
            
            return response()->json([
                'success' => true,
                'message' => 'Основной тип деятельности обновлен',
                'data' => [
                    'profile_completeness' => $profile->getProfileCompleteness(),
                    'recommended_modules' => $profile->getRecommendedModules(),
                ],
            ]);
        } catch (\Exception $e) {
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

    /**
     * Обновить специализации
     * 
     * PUT /api/v1/landing/organization/profile/specializations
     */
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
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $updatedOrg = $this->profileService->updateSpecializations(
                $organization,
                $request->input('specializations')
            );
            
            $profile = $this->profileService->getProfile($updatedOrg);
            
            return response()->json([
                'success' => true,
                'message' => 'Специализации обновлены',
                'data' => [
                    'profile_completeness' => $profile->getProfileCompleteness(),
                    'recommended_modules' => $profile->getRecommendedModules(),
                ],
            ]);
        } catch (\Exception $e) {
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

    /**
     * Обновить сертификаты/лицензии
     * 
     * PUT /api/v1/landing/organization/profile/certifications
     */
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
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $updatedOrg = $this->profileService->updateCertifications(
                $organization,
                $request->input('certifications')
            );
            
            $profile = $this->profileService->getProfile($updatedOrg);
            
            return response()->json([
                'success' => true,
                'message' => 'Сертификаты обновлены',
                'data' => [
                    'profile_completeness' => $profile->getProfileCompleteness(),
                    'recommended_modules' => $profile->getRecommendedModules(),
                ],
            ]);
        } catch (\Exception $e) {
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

    /**
     * Завершить onboarding
     * 
     * POST /api/v1/landing/organization/profile/complete-onboarding
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            // Проверяем минимальную заполненность профиля
            $profile = $this->profileService->getProfile($organization);
            
            if ($profile->getProfileCompleteness() < 50) {
                return response()->json([
                    'success' => false,
                    'message' => 'Профиль заполнен менее чем на 50%. Пожалуйста, заполните основную информацию.',
                    'data' => [
                        'completeness' => $profile->getProfileCompleteness(),
                        'required_fields' => [
                            'capabilities' => empty($profile->getCapabilities()),
                            'business_type' => empty($profile->getPrimaryBusinessType()),
                        ],
                    ],
                ], 400);
            }
            
            $updatedOrg = $this->profileService->completeOnboarding($organization);
            $profile = $this->profileService->getProfile($updatedOrg);
            
            return response()->json([
                'success' => true,
                'message' => 'Onboarding успешно завершен',
                'data' => [
                    'onboarding_completed' => $profile->isOnboardingCompleted(),
                    'onboarding_completed_at' => $profile->getOnboardingCompletedAt()?->format('Y-m-d H:i:s'),
                    'profile_completeness' => $profile->getProfileCompleteness(),
                    'recommended_modules' => $profile->getRecommendedModules(),
                ],
            ]);
        } catch (\Exception $e) {
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

    /**
     * Получить список доступных capabilities с описаниями
     * 
     * GET /api/v1/landing/organization/capabilities
     */
    public function getAvailableCapabilities(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => OrganizationCapability::toArray(),
        ]);
    }
}
