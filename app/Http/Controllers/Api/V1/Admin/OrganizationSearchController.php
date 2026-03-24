<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Contractor\OrganizationDiscoveryService;
use App\Support\Organization\OrganizationWorkspaceProfileCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OrganizationSearchController extends Controller
{
    public function __construct(
        protected OrganizationDiscoveryService $discoveryService
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('organization_search.organization_context_missing'),
                Response::HTTP_BAD_REQUEST
            );
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
        $validated['exclude_existing_contractors'] = isset($validated['exclude_existing_contractors'])
            ? filter_var($validated['exclude_existing_contractors'], FILTER_VALIDATE_BOOLEAN)
            : null;

        $filters = collect($validated)
            ->except(['sort_by', 'per_page'])
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->toArray();
        $sortBy = $validated['sort_by'] ?? 'relevance';
        $perPage = (int) ($validated['per_page'] ?? 20);

        try {
            $organizations = $this->discoveryService->searchOrganizations(
                $organizationId,
                $filters,
                $perPage,
                $sortBy
            );

            $availabilityStatuses = $this->resolveAvailabilityStatuses(
                $organizationId,
                collect($organizations->items())->pluck('id')->all()
            );

            $data = $organizations->getCollection()
                ->map(function ($organization) use ($availabilityStatuses): array {
                    return $this->decorateOrganizationPayload(
                        $organization->toArray(),
                        $availabilityStatuses[$organization->id] ?? null
                    );
                })
                ->values()
                ->all();

            return AdminResponse::paginated(
                $data,
                [
                    'current_page' => $organizations->currentPage(),
                    'from' => $organizations->firstItem(),
                    'last_page' => $organizations->lastPage(),
                    'path' => $organizations->path(),
                    'per_page' => $organizations->perPage(),
                    'to' => $organizations->lastItem(),
                    'total' => $organizations->total(),
                    'filters' => $filters,
                    'sort_by' => $sortBy,
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $organizations->url(1),
                    'last' => $organizations->url($organizations->lastPage()),
                    'prev' => $organizations->previousPageUrl(),
                    'next' => $organizations->nextPageUrl(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to search organizations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'filters' => $filters,
            ]);

            return AdminResponse::error(
                trans_message('organization_search.search_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function suggestions(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('organization_search.organization_context_missing'),
                Response::HTTP_BAD_REQUEST
            );
        }

        $validated = $request->validate([
            'query' => 'required|string|min:2|max:100',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        try {
            $suggestions = $this->discoveryService->getSearchSuggestions(
                $validated['query'],
                $organizationId,
                (int) ($validated['limit'] ?? 10)
            );

            return AdminResponse::success(
                array_map(
                    fn (array $suggestion): array => $this->decorateOrganizationPayload($suggestion),
                    $suggestions
                )
            );
        } catch (\Throwable $e) {
            Log::error('Failed to get organization suggestions', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'query' => $validated['query'],
            ]);

            return AdminResponse::error(
                trans_message('organization_search.suggestions_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function recommendations(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('organization_search.organization_context_missing'),
                Response::HTTP_BAD_REQUEST
            );
        }

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        try {
            $recommendations = $this->discoveryService->getRecommendedOrganizations(
                $organizationId,
                (int) ($validated['limit'] ?? 10)
            );
            $availabilityStatuses = $this->resolveAvailabilityStatuses(
                $organizationId,
                collect($recommendations)->pluck('id')->all()
            );

            return AdminResponse::success(
                array_map(
                    fn (array $organization): array => $this->decorateOrganizationPayload(
                        $organization,
                        $availabilityStatuses[$organization['id']] ?? null
                    ),
                    $recommendations
                )
            );
        } catch (\Throwable $e) {
            Log::error('Failed to get organization recommendations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return AdminResponse::error(
                trans_message('organization_search.recommendations_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function checkAvailability(int $targetOrganizationId, Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('organization_search.organization_context_missing'),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            return AdminResponse::success(
                $this->discoveryService->getOrganizationAvailabilityStatus(
                    $organizationId,
                    $targetOrganizationId
                )
            );
        } catch (\Throwable $e) {
            Log::error('Failed to check organization availability', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'target_organization_id' => $targetOrganizationId,
            ]);

            return AdminResponse::error(
                trans_message('organization_search.availability_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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

    private function resolveOrganizationId(Request $request): ?int
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
