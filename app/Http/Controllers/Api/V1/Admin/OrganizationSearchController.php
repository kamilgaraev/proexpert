<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contractor\OrganizationDiscoveryService;
use App\Support\Organization\OrganizationWorkspaceProfileCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $filters = collect($validated)->except(['sort_by', 'per_page'])->filter(fn ($value) => $value !== null)->toArray();
        $sortBy = $validated['sort_by'] ?? 'relevance';
        $perPage = $validated['per_page'] ?? 20;

        try {
            $organizations = $this->discoveryService->searchOrganizations(
                $organizationId,
                $filters,
                $perPage,
                $sortBy
            );

            $availabilityStatuses = $this->resolveAvailabilityStatuses($organizationId, collect($organizations->items())->pluck('id')->all());

            return response()->json([
                'success' => true,
                'data' => $organizations->getCollection()->map(function ($organization) use ($availabilityStatuses) {
                    return $this->decorateOrganizationPayload($organization->toArray(), $availabilityStatuses[$organization->id] ?? null);
                })->values(),
                'meta' => [
                    'current_page' => $organizations->currentPage(),
                    'last_page' => $organizations->lastPage(),
                    'per_page' => $organizations->perPage(),
                    'total' => $organizations->total(),
                    'filters' => $filters,
                    'sort_by' => $sortBy,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to search organizations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'filters' => $filters,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при поиске организаций',
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
                'data' => array_map(fn (array $suggestion) => $this->decorateOrganizationPayload($suggestion), $suggestions),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to get organization suggestions', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'query' => $validated['query'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подсказок',
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
            $availabilityStatuses = $this->resolveAvailabilityStatuses($organizationId, collect($recommendations)->pluck('id')->all());

            return response()->json([
                'success' => true,
                'data' => array_map(
                    fn (array $organization) => $this->decorateOrganizationPayload(
                        $organization,
                        $availabilityStatuses[$organization['id']] ?? null
                    ),
                    $recommendations
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to get organization recommendations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении рекомендаций',
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
        } catch (\Throwable $e) {
            Log::error('Failed to check organization availability', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'target_organization_id' => $targetOrganizationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при проверке доступности организации',
            ], 500);
        }
    }

    private function resolveAvailabilityStatuses(int $organizationId, array $targetOrganizationIds): array
    {
        if ($targetOrganizationIds === []) {
            return [];
        }

        return $this->discoveryService->getBulkAvailabilityStatus($organizationId, $targetOrganizationIds);
    }

    private function decorateOrganizationPayload(array $organization, ?array $availabilityStatus = null): array
    {
        $capabilities = array_values(array_filter($organization['capabilities'] ?? [], 'is_string'));

        $organization['capabilities'] = $capabilities;
        $organization['primary_business_type'] = OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
            $capabilities,
            is_string($organization['primary_business_type'] ?? null)
                ? $organization['primary_business_type']
                : null
        );
        $organization['interaction_modes'] = OrganizationWorkspaceProfileCatalog::interactionModes($capabilities);
        $organization['allowed_project_roles'] = OrganizationWorkspaceProfileCatalog::allowedProjectRoles($capabilities);
        $organization['availability_status'] = $availabilityStatus ?? ($organization['availability_status'] ?? [
            'can_invite' => true,
            'existing_invitation' => null,
            'existing_contractor' => null,
            'reverse_invitation' => null,
            'is_mutual' => false,
        ]);

        return $organization;
    }
}
