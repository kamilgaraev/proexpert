<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Organization\UpdateOrganizationRequest;
use App\Http\Responses\LandingResponse;
use App\Http\Resources\Api\V1\Landing\Organization\OrganizationResource;
use App\Models\Organization;
use App\Services\DaDataService;
use App\Services\OrganizationVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Jobs\Organization\VerifyOrganizationJob;

class OrganizationVerificationController extends Controller
{
    private OrganizationVerificationService $verificationService;
    private DaDataService $daDataService;

    public function __construct(
        OrganizationVerificationService $verificationService,
        DaDataService $daDataService
    ) {
        $this->verificationService = $verificationService;
        $this->daDataService = $daDataService;
    }

    public function show(Request $request)
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }

            // Автоматическая верификация, если все данные заполнены и верификация не проводилась
            // PERFORMANCE FIX: Отключаем синхронную авто-верификацию в GET запросе, так как она вызывает долгие внешние запросы (DaData)
            // Верификация должна вызываться явно через POST /verification/request или в фоновой задаче
            /*
            if ($organization->canBeVerified() && !$organization->is_verified) {
                $verificationResult = $this->verificationService->requestVerification($organization);
                
                Log::info('Auto-verification triggered on organization data fetch', [
                    'organization_id' => $organization->id,
                    'verification_result' => $verificationResult
                ]);
                
                // Перезагружаем организацию с обновленными данными верификации
                $organization = $organization->fresh();
            }
            */

            $recommendations = $this->verificationService->getVerificationRecommendations($organization);
            $userMessage = $this->verificationService->getUserFriendlyMessage($organization);

            return LandingResponse::success(
                [
                    'organization' => new OrganizationResource($organization),
                    'recommendations' => $recommendations,
                    'user_message' => $userMessage
                ],
                trans_message('organization.verification.loaded')
            );

        } catch (\Exception $e) {
            Log::error('Error fetching organization data', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return LandingResponse::error(trans_message('organization.verification.load_error'), 500);
        }
    }

    public function update(UpdateOrganizationRequest $request)
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }

            $organization->update($request->validated());

            // Запускаем фоновую верификацию
            if ($organization->canBeVerified() && !$organization->is_verified) {
                VerifyOrganizationJob::dispatch($organization);
                
                Log::info('Background verification dispatched after update', [
                    'organization_id' => $organization->id
                ]);
            }

            return LandingResponse::success(
                [
                    'organization' => new OrganizationResource($organization->fresh())
                ],
                trans_message('organization.verification.updated')
            );

        } catch (\Exception $e) {
            Log::error('Error updating organization data', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return LandingResponse::error(trans_message('organization.verification.update_error'), 500);
        }
    }

    public function requestVerification(Request $request)
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }

            $result = $this->verificationService->requestVerification($organization);

            if ($result['success']) {
                return LandingResponse::success(
                    [
                        'verification_result' => $result['data'],
                        'organization' => new OrganizationResource($organization->fresh())
                    ],
                    $result['message']
                );
            } else {
                return LandingResponse::error($result['message'], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error requesting organization verification', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return LandingResponse::error(trans_message('organization.verification.request_error'), 500);
        }
    }

    public function getRecommendations(Request $request)
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return LandingResponse::error(trans_message('organization.verification.organization_not_found'), 404);
            }

            // Автоматическая верификация, если все данные заполнены и верификация не проводилась
            // PERFORMANCE FIX: Отключаем синхронную авто-верификацию
            /*
            if ($organization->canBeVerified() && !$organization->is_verified) {
                $verificationResult = $this->verificationService->requestVerification($organization);
                
                Log::info('Auto-verification triggered on recommendations fetch', [
                    'organization_id' => $organization->id,
                    'verification_result' => $verificationResult
                ]);
                
                // Перезагружаем организацию с обновленными данными верификации
                $organization = $organization->fresh();
            }
            */

            $recommendations = $this->verificationService->getVerificationRecommendations($organization);
            $userMessage = $this->verificationService->getUserFriendlyMessage($organization);

            return LandingResponse::success(
                [
                    'recommendations' => $recommendations,
                    'user_message' => $userMessage
                ],
                trans_message('organization.verification.recommendations_loaded')
            );

        } catch (\Exception $e) {
            Log::error('Error getting verification recommendations', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return LandingResponse::error(trans_message('organization.verification.recommendations_error'), 500);
        }
    }

    public function suggestOrganizations(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:3|max:100'
        ]);

        try {
            $result = $this->daDataService->suggestOrganization($request->input('query'));

            if ($result['success']) {
                return LandingResponse::success($result['data'], $result['message']);
            }

            return LandingResponse::error($result['message'], 400, null, ['data' => $result['data']]);

        } catch (\Exception $e) {
            Log::error('Error suggesting organizations', [
                'query' => $request->input('query'),
                'error' => $e->getMessage()
            ]);

            return LandingResponse::error(
                trans_message('organization.verification.suggest_organizations_error'),
                500,
                null,
                ['data' => []]
            );
        }
    }

    public function suggestAddresses(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:3|max:200'
        ]);

        try {
            $result = $this->daDataService->suggestAddress($request->input('query'));

            if ($result['success']) {
                return LandingResponse::success($result['data'], $result['message']);
            }

            return LandingResponse::error($result['message'], 400, null, ['data' => $result['data']]);

        } catch (\Exception $e) {
            Log::error('Error suggesting addresses', [
                'query' => $request->input('query'),
                'error' => $e->getMessage()
            ]);

            return LandingResponse::error(
                trans_message('organization.verification.suggest_addresses_error'),
                500,
                null,
                ['data' => []]
            );
        }
    }

    public function cleanAddress(Request $request)
    {
        $request->validate([
            'address' => 'required|string|min:5|max:500'
        ]);

        try {
            $result = $this->daDataService->cleanAddress($request->input('address'));

            if ($result['success']) {
                return LandingResponse::success($result['data'], $result['message']);
            }

            return LandingResponse::error($result['message'], 400, null, ['data' => $result['data']]);

        } catch (\Exception $e) {
            Log::error('Error cleaning address', [
                'address' => $request->input('address'),
                'error' => $e->getMessage()
            ]);

            return LandingResponse::error(
                trans_message('organization.verification.clean_address_error'),
                500,
                null,
                ['data' => null]
            );
        }
    }
}
