<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDashboardSettingsRequest;
use App\Services\Admin\DashboardWidgetsRegistry;
use App\Services\Admin\UserDashboardSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class DashboardSettingsController extends Controller
{
    public function __construct(
        private DashboardWidgetsRegistry $registry,
        private UserDashboardSettingsService $service
    ) {
        // Авторизация настроена на уровне роутов через middleware стек
    }

    public function widgets(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $orgId = $request->query('org_id') ? (int)$request->query('org_id') : ($user->current_organization_id ?? null);
        $roles = method_exists($user, 'rolesInOrganization') ? $user->rolesInOrganization($orgId)->pluck('slug')->all() : [];
        $data = $this->registry->get($roles);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function get(Request $request): JsonResponse
    {
        $orgId = $request->query('org_id');
        $settings = $this->service->getMergedForCurrentUser($orgId ? (int)$orgId : null);
        if (!$settings) {
            return response()->json([], 204);
        }
        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function put(SaveDashboardSettingsRequest $request): JsonResponse
    {
        $orgId = $request->query('org_id');
        try {
            $payload = $request->validated();
            $saved = $this->service->saveForCurrentUser($payload, $orgId ? (int)$orgId : null);
            return response()->json(['success' => true, 'data' => $saved]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('[DashboardSettingsController.put] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => 'internal_error',
            ], 500);
        }
    }

    public function delete(Request $request): JsonResponse
    {
        $orgId = $request->query('org_id');
        $this->service->resetForCurrentUser($orgId ? (int)$orgId : null);
        return response()->json(['success' => true, 'message' => 'reset']);
    }

    public function defaults(Request $request): JsonResponse
    {
        $orgId = $request->query('org_id');
        $data = $this->service->defaultsForCurrentUser($orgId ? (int)$orgId : null);
        return response()->json(['success' => true, 'data' => $data]);
    }
}


