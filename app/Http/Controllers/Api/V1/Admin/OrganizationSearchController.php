<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contractor\OrganizationDiscoveryService;
use App\Http\Resources\Api\V1\Admin\Organization\OrganizationSearchResource;
use App\Http\Resources\Api\V1\Admin\Organization\OrganizationSearchCollection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OrganizationSearchController extends Controller
{
    protected OrganizationDiscoveryService $discoveryService;

    public function __construct(OrganizationDiscoveryService $discoveryService)
    {
        $this->discoveryService = $discoveryService;
    }

    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'verified' => 'nullable|in:true,false,1,0',
            'exclude_invited' => 'nullable|in:true,false,1,0',
            'exclude_existing_contractors' => 'nullable|in:true,false,1,0',
            'sort_by' => 'nullable|string|in:relevance,name,city,connections,verified',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $validated['verified'] = isset($validated['verified']) ? filter_var($validated['verified'], FILTER_VALIDATE_BOOLEAN) : null;
        $validated['exclude_invited'] = isset($validated['exclude_invited']) ? filter_var($validated['exclude_invited'], FILTER_VALIDATE_BOOLEAN) : null;
        $validated['exclude_existing_contractors'] = isset($validated['exclude_existing_contractors']) ? filter_var($validated['exclude_existing_contractors'], FILTER_VALIDATE_BOOLEAN) : null;

        $filters = collect($validated)->except(['sort_by', 'per_page'])->filter(fn($value) => $value !== null)->toArray();
        $sortBy = $validated['sort_by'] ?? 'relevance';
        $perPage = $validated['per_page'] ?? 20;

        try {
            $organizations = $this->discoveryService->searchOrganizations(
                $organizationId,
                $filters,
                $perPage,
                $sortBy
            );

            $organizationIds = $organizations->pluck('id')->toArray();
            $availabilityStatuses = [];
            
            if (!empty($organizationIds)) {
                $availabilityStatuses = $this->discoveryService->getBulkAvailabilityStatus(
                    $organizationId,
                    $organizationIds
                );
            }

            return response()->json([
                'success' => true,
                'data' => $organizations->map(function ($org) use ($availabilityStatuses) {
                    $orgArray = $org->toArray();
                    $orgArray['availability_status'] = $availabilityStatuses[$org->id] ?? [
                        'can_invite' => true,
                        'existing_invitation' => null,
                        'existing_contractor' => null,
                        'reverse_invitation' => null,
                        'is_mutual' => false,
                    ];
                    return $orgArray;
                }),
                'meta' => [
                    'current_page' => $organizations->currentPage(),
                    'last_page' => $organizations->lastPage(),
                    'per_page' => $organizations->perPage(),
                    'total' => $organizations->total(),
                    'filters' => $filters,
                    'sort_by' => $sortBy,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to search organizations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'filters' => $filters,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при поиске организаций'
            ], 500);
        }
    }

    public function suggestions(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $validated = $request->validate([
            'query' => 'required|string|min:2|max:100',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        try {
            $suggestions = $this->discoveryService->getSearchSuggestions(
                $validated['query'],
                $organizationId,
                $validated['limit'] ?? 10
            );

            return response()->json([
                'success' => true,
                'data' => $suggestions,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get organization suggestions', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'query' => $validated['query'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок'
            ], 500);
        }
    }

    public function recommendations(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        try {
            $recommendations = $this->discoveryService->getRecommendedOrganizations(
                $organizationId,
                $validated['limit'] ?? 10
            );

            $organizationIds = collect($recommendations)->pluck('id')->toArray();
            $availabilityStatuses = [];
            
            if (!empty($organizationIds)) {
                $availabilityStatuses = $this->discoveryService->getBulkAvailabilityStatus(
                    $organizationId,
                    $organizationIds
                );
            }

            $recommendationsWithStatus = collect($recommendations)->map(function ($org) use ($availabilityStatuses) {
                $org['availability_status'] = $availabilityStatuses[$org['id']] ?? [
                    'can_invite' => true,
                    'existing_invitation' => null,
                    'existing_contractor' => null,
                    'reverse_invitation' => null,
                    'is_mutual' => false,
                ];
                return $org;
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => $recommendationsWithStatus,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get organization recommendations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении рекомендаций'
            ], 500);
        }
    }

    public function checkAvailability(int $targetOrganizationId, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $status = $this->discoveryService->getOrganizationAvailabilityStatus(
                $organizationId,
                $targetOrganizationId
            );

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to check organization availability', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'target_organization_id' => $targetOrganizationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при проверке доступности организации'
            ], 500);
        }
    }
}