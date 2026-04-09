<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ProjectOrganizationRole;
use App\Http\Responses\CustomerResponse;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Services\Organization\OrganizationProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class OrganizationSearchController extends CustomerController
{
    public function __construct(
        private readonly OrganizationProfileService $organizationProfileService
    ) {
    }

    public function search(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            if (!$this->hasPermission($request, 'customer.projects.participants.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $query = trim((string) $request->query('query', ''));
            $role = (string) $request->query('role', ProjectOrganizationRole::GENERAL_CONTRACTOR->value);

            if ($query === '') {
                return CustomerResponse::success([
                    'items' => [],
                ], trans_message('customer.organizations_search_loaded'));
            }

            $targetRole = ProjectOrganizationRole::tryFrom($role) ?? ProjectOrganizationRole::GENERAL_CONTRACTOR;

            $activeParticipantIds = $project->organizations()
                ->wherePivot('is_active', true)
                ->pluck('organizations.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $pendingInvitations = ProjectParticipantInvitation::query()
                ->where('project_id', $project->id)
                ->where('status', ProjectParticipantInvitation::STATUS_PENDING)
                ->whereNotNull('invited_organization_id')
                ->pluck('invited_organization_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $organizations = Organization::query()
                ->where('id', '!=', $organizationId)
                ->where('is_active', true)
                ->where(function ($builder) use ($query): void {
                    $builder
                        ->where('name', 'ilike', '%' . $query . '%')
                        ->orWhere('legal_name', 'ilike', '%' . $query . '%')
                        ->orWhere('email', 'ilike', '%' . $query . '%')
                        ->orWhere('tax_number', 'ilike', '%' . $query . '%');
                })
                ->limit(8)
                ->get();

            $items = $organizations
                ->map(function (Organization $organization) use ($activeParticipantIds, $pendingInvitations, $targetRole): ?array {
                    $profile = $this->organizationProfileService->getProfile($organization);
                    $allowedRoles = $profile->getAllowedProjectRoles();
                    $canAssumeRole = in_array($targetRole, $allowedRoles, true);

                    if (!$canAssumeRole) {
                        return null;
                    }

                    return [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'email' => $organization->email,
                        'phone' => $organization->phone,
                        'inn' => $organization->tax_number,
                        'city' => $organization->city,
                        'is_verified' => (bool) $organization->is_verified,
                        'allowed_roles' => array_map(
                            static fn (ProjectOrganizationRole $role): array => [
                                'value' => $role->value,
                                'label' => $role->label(),
                            ],
                            $allowedRoles
                        ),
                        'availability_status' => [
                            'can_invite' => !in_array($organization->id, $activeParticipantIds, true)
                                && !in_array($organization->id, $pendingInvitations, true),
                            'already_participant' => in_array($organization->id, $activeParticipantIds, true),
                            'pending_invitation' => in_array($organization->id, $pendingInvitations, true),
                        ],
                    ];
                })
                ->filter()
                ->values()
                ->all();

            return CustomerResponse::success([
                'items' => $items,
            ], trans_message('customer.organizations_search_loaded'));
        } catch (Throwable $exception) {
            Log::error('customer.organizations.search.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'query' => $request->query('query'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.organizations_search_error'), 500);
        }
    }
}
