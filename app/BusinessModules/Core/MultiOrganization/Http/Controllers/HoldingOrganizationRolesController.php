<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\Landing\ChildOrganizationUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class HoldingOrganizationRolesController extends Controller
{
    public function __construct(
        private readonly ChildOrganizationUserService $childUserService
    ) {}

    public function templates(Request $request): JsonResponse
    {
        try {
            return LandingResponse::success([
                'templates' => $this->childUserService->getAvailableRoleTemplates(),
                'permissions_groups' => $this->permissionsGroups(),
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'templates');
        }
    }

    public function index(Request $request, int $childOrgId): JsonResponse
    {
        try {
            return LandingResponse::success($this->childUserService->getOrganizationRolesWithStatsForParent(
                $this->currentOrganizationId($request),
                $childOrgId
            ));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'index');
        }
    }

    private function currentOrganizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()->current_organization_id);
    }

    private function permissionsGroups(): array
    {
        return [
            trans_message('permissions.groups.users') => [
                'users.view' => trans_message('permissions.values.users.view'),
                'users.create' => trans_message('permissions.values.users.create'),
                'users.edit' => trans_message('permissions.values.users.edit'),
                'users.delete' => trans_message('permissions.values.users.delete'),
            ],
            trans_message('permissions.values.roles') => [
                'roles.view' => trans_message('permissions.values.roles.view'),
                'roles.create' => trans_message('permissions.values.roles.create'),
                'roles.edit' => trans_message('permissions.values.roles.edit'),
                'roles.delete' => trans_message('permissions.values.roles.delete'),
            ],
            trans_message('permissions.groups.project_management') => [
                'projects.view' => trans_message('permissions.values.projects.view'),
                'projects.create' => trans_message('permissions.values.projects.create'),
                'projects.edit' => trans_message('permissions.values.projects.edit'),
                'projects.delete' => trans_message('permissions.values.projects.delete'),
            ],
            trans_message('permissions.groups.contract_management') => [
                'contracts.view' => trans_message('permissions.values.contracts.view'),
                'contracts.create' => trans_message('permissions.values.contracts.create'),
                'contracts.edit' => trans_message('permissions.values.contracts.edit'),
                'contracts.delete' => trans_message('permissions.values.contracts.delete'),
            ],
            trans_message('permissions.groups.reports') => [
                'reports.view' => trans_message('permissions.values.reports.view'),
                'reports.create' => trans_message('permissions.values.reports.create'),
                'reports.export' => trans_message('permissions.values.reports.export'),
            ],
        ];
    }

    private function fail(Throwable $e, Request $request, string $action): JsonResponse
    {
        Log::error("[HoldingOrganizationRolesController.{$action}] Failed", [
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
