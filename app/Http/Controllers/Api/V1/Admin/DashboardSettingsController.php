<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDashboardSettingsRequest;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\Admin\DashboardWidgetsRegistry;
use App\Services\Admin\UserDashboardSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DashboardSettingsController extends Controller
{
    public function __construct(
        private DashboardWidgetsRegistry $registry,
        private UserDashboardSettingsService $service
    ) {
    }

    public function widgets(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = Auth::user();
            $organizationId = $request->query('org_id')
                ? (int) $request->query('org_id')
                : ($user->current_organization_id ?? null);
            $roles = method_exists($user, 'rolesInOrganization')
                ? $user->rolesInOrganization($organizationId)->pluck('slug')->all()
                : [];

            return AdminResponse::success($this->registry->get($roles));
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('widgets', $e, $request);
        }
    }

    public function get(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->query('org_id');
            $settings = $this->service->getMergedForCurrentUser($organizationId ? (int) $organizationId : null);

            return AdminResponse::success($settings);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('get', $e, $request);
        }
    }

    public function put(SaveDashboardSettingsRequest $request): JsonResponse
    {
        $organizationId = $request->query('org_id');

        try {
            $saved = $this->service->saveForCurrentUser(
                $request->validated(),
                $organizationId ? (int) $organizationId : null
            );

            return AdminResponse::success($saved);
        } catch (HttpExceptionInterface $e) {
            return AdminResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : trans_message('dashboard.settings_save_error'),
                $e->getStatusCode()
            );
        } catch (\Throwable $e) {
            Log::error('[DashboardSettingsController.put] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('dashboard.settings_internal_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function delete(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->query('org_id');
            $this->service->resetForCurrentUser($organizationId ? (int) $organizationId : null);

            return AdminResponse::success(null, trans_message('dashboard.settings_reset'));
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('delete', $e, $request);
        }
    }

    public function defaults(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->query('org_id');

            return AdminResponse::success(
                $this->service->defaultsForCurrentUser($organizationId ? (int) $organizationId : null)
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('defaults', $e, $request);
        }
    }

    private function handleUnexpectedError(string $action, \Throwable $e, Request $request): JsonResponse
    {
        Log::error("[DashboardSettingsController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'user_id' => $request->user()?->id,
        ]);

        return AdminResponse::error(
            trans_message('dashboard.settings_internal_error'),
            Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }
}
