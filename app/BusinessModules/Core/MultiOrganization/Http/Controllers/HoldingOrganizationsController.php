<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Requests\CreateHoldingRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\DeleteChildOrganizationRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\ListChildOrganizationsRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\StoreChildOrganizationRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\SwitchOrganizationContextRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\UpdateChildOrganizationRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\UpdateHoldingSettingsRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\Landing\MultiOrganizationService;
use App\Services\Landing\OrganizationModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class HoldingOrganizationsController extends Controller
{
    public function __construct(
        private readonly MultiOrganizationService $multiOrgService,
        private readonly OrganizationModuleService $moduleService
    ) {}

    public function checkAvailability(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $this->currentOrganizationId($request);
            $hasModule = $this->moduleService->hasModuleAccess($organizationId, 'multi-organization');

            if (! $hasModule) {
                return LandingResponse::error(
                    trans_message('landing.multi_organization.module_inactive'),
                    Response::HTTP_FORBIDDEN,
                    null,
                    ['available' => false, 'required_module' => 'multi-organization']
                );
            }

            $organization = $user->currentOrganization;

            return LandingResponse::success([
                'available' => true,
                'can_create_holding' => ! ($organization->is_holding ?? false),
                'current_type' => $organization->organization_type ?? 'single',
                'is_holding' => $organization->is_holding ?? false,
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'checkAvailability');
        }
    }

    public function createHolding(CreateHoldingRequest $request): JsonResponse
    {
        try {
            $group = $this->multiOrgService->createOrganizationGroup($request->user(), $request->validated());

            return LandingResponse::success(
                $group,
                trans_message('landing.multi_organization.holding_created')
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'createHolding');
        }
    }

    public function addChildOrganization(StoreChildOrganizationRequest $request): JsonResponse
    {
        try {
            $childData = $this->multiOrgService->addChildOrganizationForParent(
                $this->currentOrganizationId($request),
                $request->validated(),
                $request->user()
            );

            return LandingResponse::success(
                $childData,
                trans_message('landing.multi_organization.child_added')
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'addChildOrganization');
        }
    }

    public function getHierarchy(Request $request): JsonResponse
    {
        try {
            return LandingResponse::success(
                $this->multiOrgService->getOrganizationHierarchy($this->currentOrganizationId($request))
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'getHierarchy');
        }
    }

    public function getAccessibleOrganizations(Request $request): JsonResponse
    {
        try {
            $organizations = $this->multiOrgService->getAccessibleOrganizations($request->user());

            return LandingResponse::success($organizations->map(static fn ($org): array => [
                'id' => $org->id,
                'name' => $org->name,
                'organization_type' => $org->organization_type ?? 'single',
                'is_holding' => $org->is_holding ?? false,
                'hierarchy_level' => $org->hierarchy_level ?? 0,
            ]));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'getAccessibleOrganizations');
        }
    }

    public function getOrganizationData(Request $request, int $organizationId): JsonResponse
    {
        try {
            return LandingResponse::success(
                $this->multiOrgService->getOrganizationData($organizationId, $request->user())
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'getOrganizationData');
        }
    }

    public function switchOrganizationContext(SwitchOrganizationContextRequest $request): JsonResponse
    {
        try {
            $targetOrgId = (int) $request->validated('organization_id');
            $this->multiOrgService->switchOrganizationContext($request->user(), $targetOrgId);

            return LandingResponse::success(
                ['current_organization_id' => $targetOrgId],
                trans_message('landing.multi_organization.context_changed')
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'switchOrganizationContext');
        }
    }

    public function getChildOrganizations(ListChildOrganizationsRequest $request): JsonResponse
    {
        try {
            return LandingResponse::success($this->multiOrgService->getChildOrganizations(
                $this->currentOrganizationId($request),
                array_merge([
                    'status' => 'active',
                    'sort_by' => 'name',
                    'sort_direction' => 'asc',
                    'per_page' => 15,
                ], $request->validated())
            ));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'getChildOrganizations');
        }
    }

    public function updateChildOrganization(UpdateChildOrganizationRequest $request, int $childOrgId): JsonResponse
    {
        try {
            $updatedOrg = $this->multiOrgService->updateChildOrganization(
                $this->currentOrganizationId($request),
                $childOrgId,
                $request->validated(),
                $request->user()
            );

            return LandingResponse::success(
                $updatedOrg,
                trans_message('landing.multi_organization.child_updated')
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'updateChildOrganization');
        }
    }

    public function deleteChildOrganization(DeleteChildOrganizationRequest $request, int $childOrgId): JsonResponse
    {
        try {
            $this->multiOrgService->deleteChildOrganization(
                $this->currentOrganizationId($request),
                $childOrgId,
                $request->user(),
                $request->validated('transfer_data_to')
            );

            return LandingResponse::success(null, trans_message('landing.multi_organization.child_deleted'));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'deleteChildOrganization');
        }
    }

    public function getChildOrganizationStats(Request $request, int $childOrgId): JsonResponse
    {
        try {
            return LandingResponse::success($this->multiOrgService->getChildOrganizationStats(
                $this->currentOrganizationId($request),
                $childOrgId
            ));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'getChildOrganizationStats');
        }
    }

    public function updateHoldingSettings(UpdateHoldingSettingsRequest $request): JsonResponse
    {
        try {
            $group = $this->multiOrgService->updateHoldingSettings(
                (int) $request->validated('group_id'),
                $request->validated(),
                $request->user()
            );

            return LandingResponse::success(
                $group,
                trans_message('landing.multi_organization.settings_updated')
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'updateHoldingSettings');
        }
    }

    private function currentOrganizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()->current_organization_id);
    }

    private function fail(Throwable $e, Request $request, string $action): JsonResponse
    {
        Log::error("[HoldingOrganizationsController.{$action}] Failed", [
            'message' => $e->getMessage(),
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
        ]);

        $status = $e->getCode() >= 400 && $e->getCode() < 600
            ? $e->getCode()
            : Response::HTTP_BAD_REQUEST;

        return LandingResponse::error(
            $status === Response::HTTP_FORBIDDEN
                ? trans_message('landing.multi_organization.child_access_denied')
                : trans_message('errors.business_logic_error'),
            $status
        );
    }
}
