<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Services\Landing\MultiOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class HoldingApiController extends Controller
{
    public function __construct(
        protected MultiOrganizationService $multiOrganizationService
    ) {
    }

    public function getPublicData(string $slug): JsonResponse
    {
        try {
            $group = $this->findHolding($slug);

            if (! $group) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $parentOrg = $group->parentOrganization;

            if (! $parentOrg) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.parent_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return LandingResponse::success([
                'holding' => $this->formatHolding($group),
                'parent_organization' => $this->formatOrganization($parentOrg),
                'stats' => $this->buildHoldingStats($parentOrg),
            ]);
        } catch (Throwable $exception) {
            $this->logFailure('holding_api.public_data_failed', $exception, [
                'slug' => $slug,
            ]);

            return LandingResponse::error(
                trans_message('landing.holding_api.public_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function getDashboardData(string $slug, Request $request): JsonResponse
    {
        try {
            $group = $this->findHolding($slug);

            if (! $group) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $user = $request->user();

            if (! $user) {
                return LandingResponse::error(
                    trans_message('landing.not_authenticated'),
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $parentOrg = $group->parentOrganization;

            if (! $parentOrg) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.parent_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if (! $this->multiOrganizationService->hasAccessToOrganization($user, $parentOrg->id)) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.management_access_denied'),
                    Response::HTTP_FORBIDDEN
                );
            }

            return LandingResponse::success([
                'holding' => $this->formatHolding($group),
                'hierarchy' => $this->multiOrganizationService->getOrganizationHierarchy($parentOrg->id),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'consolidated_stats' => array_merge(
                    $this->buildHoldingStats($parentOrg),
                    [
                        'recent_activity' => $this->buildRecentActivity($parentOrg),
                        'performance_metrics' => [
                            'monthly_growth' => 0,
                            'efficiency_score' => 0,
                            'satisfaction_index' => 0,
                        ],
                    ]
                ),
            ]);
        } catch (Throwable $exception) {
            $this->logFailure('holding_api.dashboard_failed', $exception, [
                'slug' => $slug,
                'user_id' => $request->user()?->id,
            ]);

            return LandingResponse::error(
                trans_message('landing.holding_api.dashboard_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function getOrganizations(string $slug, Request $request): JsonResponse
    {
        try {
            $group = $this->findHolding($slug);

            if (! $group) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $user = $request->user();

            if (! $user) {
                return LandingResponse::error(
                    trans_message('landing.not_authenticated'),
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $parentOrg = $group->parentOrganization;

            if (! $parentOrg) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.parent_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if (! $this->multiOrganizationService->hasAccessToOrganization($user, $parentOrg->id)) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.organizations_access_denied'),
                    Response::HTTP_FORBIDDEN
                );
            }

            $organizations = $parentOrg->childOrganizations
                ->map(fn (Organization $organization): array => $this->formatOrganizationWithStats($organization))
                ->values();

            return LandingResponse::success($organizations);
        } catch (Throwable $exception) {
            $this->logFailure('holding_api.organizations_failed', $exception, [
                'slug' => $slug,
                'user_id' => $request->user()?->id,
            ]);

            return LandingResponse::error(
                trans_message('landing.holding_api.organizations_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function getOrganizationData(string $slug, int $organizationId, Request $request): JsonResponse
    {
        try {
            $group = $this->findHolding($slug);

            if (! $group) {
                return LandingResponse::error(
                    trans_message('landing.holding_api.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $user = $request->user();

            if (! $user) {
                return LandingResponse::error(
                    trans_message('landing.not_authenticated'),
                    Response::HTTP_UNAUTHORIZED
                );
            }

            return LandingResponse::success(
                $this->multiOrganizationService->getOrganizationData($organizationId, $user)
            );
        } catch (Throwable $exception) {
            $this->logFailure('holding_api.organization_data_failed', $exception, [
                'slug' => $slug,
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
            ]);

            return LandingResponse::error(
                trans_message('landing.holding_api.organization_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function findHolding(string $slug): ?OrganizationGroup
    {
        return OrganizationGroup::query()
            ->where('slug', $slug)
            ->first();
    }

    private function formatHolding(OrganizationGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'description' => $group->description,
            'parent_organization_id' => $group->parent_organization_id,
            'status' => $group->status,
            'created_at' => $group->created_at,
        ];
    }

    private function formatOrganization(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'tax_number' => $organization->tax_number,
            'registration_number' => $organization->registration_number,
            'address' => $organization->address,
            'phone' => $organization->phone,
            'email' => $organization->email,
            'city' => $organization->city,
            'description' => $organization->description,
        ];
    }

    private function formatOrganizationWithStats(Organization $organization): array
    {
        return array_merge($this->formatOrganization($organization), [
            'organization_type' => $organization->organization_type,
            'hierarchy_level' => $organization->hierarchy_level,
            'created_at' => $organization->created_at,
            'stats' => [
                'users_count' => $organization->users()->count(),
                'projects_count' => $organization->projects()->count(),
                'contracts_count' => $organization->contracts()->count(),
                'active_contracts_value' => $organization->contracts()
                    ->where('status', 'active')
                    ->sum('total_amount'),
            ],
        ]);
    }

    private function buildHoldingStats(Organization $parentOrg): array
    {
        $childOrgs = $parentOrg->childOrganizations;

        return [
            'total_child_organizations' => $childOrgs->count(),
            'total_users' => $parentOrg->users()->count() + $childOrgs->sum(fn ($org) => $org->users()->count()),
            'total_projects' => $parentOrg->projects()->count() + $childOrgs->sum(fn ($org) => $org->projects()->count()),
            'total_contracts' => $parentOrg->contracts()->count() + $childOrgs->sum(fn ($org) => $org->contracts()->count()),
            'total_contracts_value' => $parentOrg->contracts()->sum('total_amount') + $childOrgs->sum(fn ($org) => $org->contracts()->sum('total_amount')),
            'active_contracts_count' => $parentOrg->contracts()->where('status', 'active')->count() + $childOrgs->sum(fn ($org) => $org->contracts()->where('status', 'active')->count()),
        ];
    }

    private function buildRecentActivity(Organization $parentOrg): array
    {
        $activity = [];

        foreach ($parentOrg->childOrganizations as $childOrg) {
            $lastProject = $childOrg->projects()->latest()->first();
            $lastContract = $childOrg->contracts()->latest()->first();

            if ($lastProject) {
                $activity[] = [
                    'type' => 'project_created',
                    'organization_name' => $childOrg->name,
                    'description' => trans_message('landing.holding_api.project_created', [
                        'name' => $lastProject->name,
                    ]),
                    'date' => $lastProject->created_at,
                ];
            }

            if ($lastContract) {
                $activity[] = [
                    'type' => 'contract_signed',
                    'organization_name' => $childOrg->name,
                    'description' => trans_message('landing.holding_api.contract_signed', [
                        'name' => $lastContract->name,
                    ]),
                    'date' => $lastContract->created_at,
                ];
            }
        }

        usort($activity, fn (array $left, array $right): int => $right['date'] <=> $left['date']);

        return array_slice($activity, 0, 10);
    }

    private function logFailure(string $event, Throwable $exception, array $context = []): void
    {
        Log::error($event, array_merge($context, [
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
        ]));
    }
}
