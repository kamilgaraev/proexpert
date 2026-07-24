<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Requests\BulkStoreChildOrganizationUsersRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\ListChildOrganizationUsersRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\StoreChildOrganizationUserRequest;
use App\BusinessModules\Core\MultiOrganization\Requests\UpdateChildOrganizationUserRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\Landing\ChildOrganizationUserService;
use App\Services\Landing\MultiOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class HoldingOrganizationUsersController extends Controller
{
    public function __construct(
        private readonly MultiOrganizationService $multiOrgService,
        private readonly ChildOrganizationUserService $childUserService
    ) {}

    public function index(ListChildOrganizationUsersRequest $request, int $childOrgId): JsonResponse
    {
        try {
            return LandingResponse::success($this->multiOrgService->getChildOrganizationUsers(
                $this->currentOrganizationId($request),
                $childOrgId,
                array_merge([
                    'status' => 'active',
                    'per_page' => 15,
                ], $request->validated())
            ));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'index');
        }
    }

    public function store(StoreChildOrganizationUserRequest $request, int $childOrgId): JsonResponse
    {
        try {
            $result = $this->childUserService->createUserWithRoleForParent(
                $this->currentOrganizationId($request),
                $childOrgId,
                $request->validated(),
                $request->user()
            );

            return LandingResponse::success(
                $result,
                trans_message('landing.multi_organization.child_user_added')
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'store');
        }
    }

    public function bulk(BulkStoreChildOrganizationUsersRequest $request, int $childOrgId): JsonResponse
    {
        try {
            $results = $this->childUserService->createBulkUsersForParent(
                $this->currentOrganizationId($request),
                $childOrgId,
                $request->validated('users'),
                $request->user()
            );

            return LandingResponse::success(
                $results,
                trans_message('landing.multi_organization.users_import_result', [
                    'total' => $results['total'],
                    'successful' => $results['successful'],
                    'failed' => $results['failed'],
                ])
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'bulk');
        }
    }

    public function update(UpdateChildOrganizationUserRequest $request, int $childOrgId, int $userId): JsonResponse
    {
        try {
            $result = $this->multiOrgService->updateUserInChildOrganization(
                $this->currentOrganizationId($request),
                $childOrgId,
                $userId,
                $request->validated(),
                $request->user()
            );

            return LandingResponse::success(
                $result,
                trans_message('landing.multi_organization.child_user_updated')
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'update');
        }
    }

    public function destroy(Request $request, int $childOrgId, int $userId): JsonResponse
    {
        try {
            $this->multiOrgService->removeUserFromChildOrganization(
                $this->currentOrganizationId($request),
                $childOrgId,
                $userId,
                $request->user()
            );

            return LandingResponse::success(null, trans_message('landing.multi_organization.child_user_removed'));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'destroy');
        }
    }

    private function currentOrganizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()->current_organization_id);
    }

    private function fail(Throwable $e, Request $request, string $action): JsonResponse
    {
        Log::error("[HoldingOrganizationUsersController.{$action}] Failed", [
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
