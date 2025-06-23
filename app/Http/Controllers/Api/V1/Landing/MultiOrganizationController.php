<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\Landing\MultiOrganizationService;
use App\Services\Landing\OrganizationModuleService;
use App\Models\OrganizationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Responses\Api\V1\ErrorResponse;

class MultiOrganizationController extends Controller
{
    protected MultiOrganizationService $multiOrgService;
    protected OrganizationModuleService $moduleService;

    public function __construct(
        MultiOrganizationService $multiOrgService,
        OrganizationModuleService $moduleService
    ) {
        $this->multiOrgService = $multiOrgService;
        $this->moduleService = $moduleService;
    }

    public function checkAvailability(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        $hasModule = $this->moduleService->hasModuleAccess($organizationId, 'multi_organization');
        
        if (!$hasModule) {
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Модуль "Мультиорганизация" не активирован',
                'required_module' => 'multi_organization'
            ], 403);
        }

        $organization = $user->currentOrganization;
        
        return response()->json([
            'success' => true,
            'available' => true,
            'can_create_holding' => !($organization->is_holding ?? false),
            'current_type' => $organization->organization_type ?? 'single',
            'is_holding' => $organization->is_holding ?? false,
        ]);
    }

    public function createHolding(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'max_child_organizations' => 'sometimes|integer|min:1|max:50',
            'settings' => 'sometimes|array',
            'permissions_config' => 'sometimes|array',
        ]);

        $user = Auth::user();
        
        try {
            $group = $this->multiOrgService->createOrganizationGroup($user, $request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Холдинг успешно создан',
                'data' => $group,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function addChildOrganization(Request $request): JsonResponse
    {
        $request->validate([
            'group_id' => 'required|integer|exists:organization_groups,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'inn' => 'nullable|string|max:12',
            'kpp' => 'nullable|string|max:9',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
        ]);

        $user = Auth::user();
        $group = OrganizationGroup::findOrFail($request->input('group_id'));
        
        if ($group->parent_organization_id !== $user->current_organization_id) {
            return (new ErrorResponse('Нет прав для добавления дочерней организации', 403))->toResponse($request);
        }

        try {
            $childOrg = $this->multiOrgService->addChildOrganization($group, $request->all(), $user);
            
            return response()->json([
                'success' => true,
                'message' => 'Дочерняя организация успешно добавлена',
                'data' => $childOrg,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getHierarchy(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        try {
            $hierarchy = $this->multiOrgService->getOrganizationHierarchy($organizationId);
            
            return response()->json([
                'success' => true,
                'data' => $hierarchy,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getAccessibleOrganizations(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizations = $this->multiOrgService->getAccessibleOrganizations($user);
        
        return response()->json([
            'success' => true,
            'data' => $organizations->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'organization_type' => $org->organization_type ?? 'single',
                    'is_holding' => $org->is_holding ?? false,
                    'hierarchy_level' => $org->hierarchy_level ?? 0,
                ];
            }),
        ]);
    }

    public function getOrganizationData(Request $request, int $organizationId): JsonResponse
    {
        $user = Auth::user();
        
        try {
            $data = $this->multiOrgService->getOrganizationData($organizationId, $user);
            
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 403))->toResponse($request);
        }
    }

    public function switchOrganizationContext(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $user = Auth::user();
        $targetOrgId = $request->input('organization_id');
        
        if (!$this->multiOrgService->hasAccessToOrganization($user, $targetOrgId)) {
            return (new ErrorResponse('Нет доступа к выбранной организации', 403))->toResponse($request);
        }

        $user->current_organization_id = $targetOrgId;
        $user->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Контекст организации изменен',
            'current_organization_id' => $targetOrgId,
        ]);
    }
} 