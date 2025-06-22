<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Organization\UpdateOrganizationRequest;
use App\Http\Resources\Api\V1\Landing\Organization\OrganizationResource;
use App\Models\Organization;
use App\Services\DaDataService;
use App\Services\OrganizationVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }

            $recommendations = $this->verificationService->getVerificationRecommendations($organization);

            return response()->json([
                'success' => true,
                'message' => 'Данные организации получены',
                'data' => [
                    'organization' => new OrganizationResource($organization),
                    'recommendations' => $recommendations
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching organization data', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении данных организации',
            ], 500);
        }
    }

    public function update(UpdateOrganizationRequest $request)
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }

            $organization->update($request->validated());

            if ($organization->canBeVerified() && !$organization->is_verified) {
                $verificationResult = $this->verificationService->requestVerification($organization);
                
                Log::info('Auto-verification triggered after update', [
                    'organization_id' => $organization->id,
                    'verification_result' => $verificationResult
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Данные организации обновлены',
                'data' => [
                    'organization' => new OrganizationResource($organization->fresh())
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating organization data', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении данных организации',
            ], 500);
        }
    }

    public function requestVerification(Request $request)
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }

            $result = $this->verificationService->requestVerification($organization);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => [
                        'verification_result' => $result['data'],
                        'organization' => new OrganizationResource($organization->fresh())
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error requesting organization verification', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при запросе верификации',
            ], 500);
        }
    }

    public function getRecommendations(Request $request)
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $organization = Organization::find($organizationId);

            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }

            $recommendations = $this->verificationService->getVerificationRecommendations($organization);

            return response()->json([
                'success' => true,
                'message' => 'Рекомендации по верификации получены',
                'data' => $recommendations
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting verification recommendations', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении рекомендаций',
            ], 500);
        }
    }

    public function suggestOrganizations(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:3|max:100'
        ]);

        try {
            $result = $this->daDataService->suggestOrganization($request->input('query'));

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['data']
            ]);

        } catch (\Exception $e) {
            Log::error('Error suggesting organizations', [
                'query' => $request->input('query'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при поиске организаций',
                'data' => []
            ], 500);
        }
    }

    public function suggestAddresses(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:3|max:200'
        ]);

        try {
            $result = $this->daDataService->suggestAddress($request->input('query'));

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['data']
            ]);

        } catch (\Exception $e) {
            Log::error('Error suggesting addresses', [
                'query' => $request->input('query'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при поиске адресов',
                'data' => []
            ], 500);
        }
    }

    public function cleanAddress(Request $request)
    {
        $request->validate([
            'address' => 'required|string|min:5|max:500'
        ]);

        try {
            $result = $this->daDataService->cleanAddress($request->input('address'));

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['data']
            ]);

        } catch (\Exception $e) {
            Log::error('Error cleaning address', [
                'address' => $request->input('address'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обработке адреса',
                'data' => null
            ], 500);
        }
    }
}
