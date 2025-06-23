<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\Landing\OrganizationModuleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResponse;

class OrganizationModuleController extends Controller
{
    protected OrganizationModuleService $moduleService;

    public function __construct(OrganizationModuleService $moduleService)
    {
        $this->moduleService = $moduleService;
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $modules = $this->moduleService->getOrganizationModulesWithStatus($organizationId);

        return (new SuccessResponse($modules))->toResponse($request);
    }

    public function available(): JsonResponse
    {
        $modules = $this->moduleService->getModulesByCategory();

        return response()->json([
            'success' => true,
            'data' => $modules,
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $request->validate([
            'module_id' => 'required|integer|exists:organization_modules,id',
            'payment_method' => 'sometimes|string|in:balance,card,invoice',
            'settings' => 'sometimes|array',
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        try {
            $activation = $this->moduleService->activateModule(
                $organizationId,
                $request->input('module_id'),
                [
                    'payment_method' => $request->input('payment_method', 'balance'),
                    'settings' => $request->input('settings', []),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Модуль успешно активирован',
                'data' => $activation,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function deactivate(Request $request, int $moduleId): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $result = $this->moduleService->deactivateModule($organizationId, $moduleId);

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Модуль успешно деактивирован',
            ]);
        }

        return (new ErrorResponse('Не удалось деактивировать модуль', 400))->toResponse($request);
    }

    public function renew(Request $request, int $moduleId): JsonResponse
    {
        $request->validate([
            'days' => 'sometimes|integer|min:1|max:365',
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        try {
            $activation = $this->moduleService->renewModule(
                $organizationId,
                $moduleId,
                $request->input('days', 30)
            );

            return response()->json([
                'success' => true,
                'message' => 'Модуль успешно продлен',
                'data' => $activation,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function checkAccess(Request $request): JsonResponse
    {
        $request->validate([
            'module_slug' => 'required|string',
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $hasAccess = $this->moduleService->hasModuleAccess(
            $organizationId,
            $request->input('module_slug')
        );

        return response()->json([
            'success' => true,
            'has_access' => $hasAccess,
        ]);
    }

    public function expiring(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $expiringModules = $this->moduleService->getExpiringModules($organizationId);

        return response()->json([
            'success' => true,
            'data' => $expiringModules,
        ]);
    }
} 