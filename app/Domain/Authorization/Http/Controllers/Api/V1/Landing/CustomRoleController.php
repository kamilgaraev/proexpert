<?php

namespace App\Domain\Authorization\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Http\Requests\CreateCustomRoleRequest;
use App\Domain\Authorization\Http\Requests\UpdateCustomRoleRequest;
use App\Domain\Authorization\Http\Resources\CustomRoleResource;
use App\Services\PermissionTranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * РљРѕРЅС‚СЂРѕР»Р»РµСЂ РґР»СЏ СѓРїСЂР°РІР»РµРЅРёСЏ РєР°СЃС‚РѕРјРЅС‹РјРё СЂРѕР»СЏРјРё РѕСЂРіР°РЅРёР·Р°С†РёР№
 */
class CustomRoleController extends Controller
{
    protected CustomRoleService $roleService;

    public function __construct(CustomRoleService $roleService)
    {
        $this->roleService = $roleService;
        
        // РџСЂРёРјРµРЅСЏРµРј middleware Р°РІС‚РѕСЂРёР·Р°С†РёРё
        $this->middleware('authorize:roles.view_custom,organization')->only(['index', 'show']);
        $this->middleware('authorize:roles.create_custom,organization')->only(['store', 'getAvailablePermissions']);
        $this->middleware('authorize:roles.manage_custom,organization')->only(['update', 'destroy', 'clone']);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРїРёСЃРѕРє РєР°СЃС‚РѕРјРЅС‹С… СЂРѕР»РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        $roles = $this->roleService->getOrganizationRoles($organizationId);
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'data' => CustomRoleResource::collection($roles),
            'meta' => [
                'total' => $roles->count(),
                'organization_id' => $organizationId
            ]
        ]);
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІСѓСЋ РєР°СЃС‚РѕРјРЅСѓСЋ СЂРѕР»СЊ
     */
    public function store(CreateCustomRoleRequest $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        $role = $this->roleService->createRole(
            $organizationId,
            $request->validated('name'),
            $request->validated('system_permissions', []),
            $request->validated('module_permissions', []),
            $request->validated('interface_access', ['lk']),
            $request->validated('conditions'),
            $request->validated('description'),
            $request->user()
        );

        return \App\Http\Responses\LandingResponse::fromPayload([
            'data' => new CustomRoleResource($role),
            'message' => 'Р РѕР»СЊ СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅР°'
        ], 201);
    }

    /**
     * РџРѕРєР°Р·Р°С‚СЊ РґРµС‚Р°Р»Рё РєР°СЃС‚РѕРјРЅРѕР№ СЂРѕР»Рё
     */
    public function show(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        return \App\Http\Responses\LandingResponse::fromPayload([
            'data' => new CustomRoleResource($role->load(['createdBy', 'assignments.user']))
        ]);
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ РєР°СЃС‚РѕРјРЅСѓСЋ СЂРѕР»СЊ
     */
    public function update(UpdateCustomRoleRequest $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->roleService->updateRole($role, $request->validated(), $request->user());
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'data' => new CustomRoleResource($role->fresh()),
            'message' => 'Р РѕР»СЊ СѓСЃРїРµС€РЅРѕ РѕР±РЅРѕРІР»РµРЅР°'
        ]);
    }

    /**
     * РЈРґР°Р»РёС‚СЊ РєР°СЃС‚РѕРјРЅСѓСЋ СЂРѕР»СЊ
     */
    public function destroy(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->roleService->deleteRole($role);
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'message' => 'Р РѕР»СЊ СѓСЃРїРµС€РЅРѕ СѓРґР°Р»РµРЅР°'
        ]);
    }

    /**
     * РљР»РѕРЅРёСЂРѕРІР°С‚СЊ СЂРѕР»СЊ
     */
    public function clone(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'target_organization_id' => 'sometimes|required|integer|exists:organizations,id'
        ]);
        
        $targetOrganizationId = $request->input('target_organization_id', $role->organization_id);
        
        $clonedRole = $this->roleService->cloneRole(
            $role,
            $targetOrganizationId,
            $request->input('name'),
            $request->user()
        );
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'data' => new CustomRoleResource($clonedRole),
            'message' => 'Р РѕР»СЊ СѓСЃРїРµС€РЅРѕ РєР»РѕРЅРёСЂРѕРІР°РЅР°'
        ], 201);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґРѕСЃС‚СѓРїРЅС‹Рµ РїСЂР°РІР° РґР»СЏ СЃРѕР·РґР°РЅРёСЏ СЂРѕР»Рё
     */
    public function getAvailablePermissions(Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        $systemPermissions = $this->roleService->getAvailableSystemPermissions($organizationId);
        $modulePermissions = $this->roleService->getAvailableModulePermissions($organizationId);
        
        $permissionsData = [
            'system_permissions' => $systemPermissions,
            'module_permissions' => $modulePermissions,
            'interface_access' => [
                'lk' => 'Р›РёС‡РЅС‹Р№ РєР°Р±РёРЅРµС‚',
                'mobile' => 'РњРѕР±РёР»СЊРЅРѕРµ РїСЂРёР»РѕР¶РµРЅРёРµ',
                'admin' => 'РђРґРјРёРЅРёСЃС‚СЂР°С‚РёРІРЅР°СЏ РїР°РЅРµР»СЊ'
            ]
        ];
        
        $translatedData = app(PermissionTranslationService::class)
            ->processPermissionsForFrontend($permissionsData);
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'data' => $translatedData
        ]);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№ СЃ СѓРєР°Р·Р°РЅРЅРѕР№ СЂРѕР»СЊСЋ
     */
    public function getUsers(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $users = $this->roleService->getRoleUsers($role);
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'data' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'assigned_at' => $user->roleAssignments()
                        ->where('role_slug', request()->route('role')->slug)
                        ->first()?->created_at
                ];
            })
        ]);
    }

    /**
     * РќР°Р·РЅР°С‡РёС‚СЊ СЂРѕР»СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЋ
     */
    public function assignToUser(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'context_type' => 'required|in:organization,project',
            'context_id' => 'required|integer',
            'expires_at' => 'sometimes|nullable|date|after:now'
        ]);
        
        // Р—РґРµСЃСЊ РЅСѓР¶РЅР° РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ Р»РѕРіРёРєР° РґР»СЏ РЅР°Р·РЅР°С‡РµРЅРёСЏ СЂРѕР»Рё
        // Р­С‚Рѕ Р·Р°РІРёСЃРёС‚ РѕС‚ РєРѕРЅС‚РµРєСЃС‚Р° Рё РјРѕР¶РµС‚ С‚СЂРµР±РѕРІР°С‚СЊ РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅРѕР№ Р°РІС‚РѕСЂРёР·Р°С†РёРё
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'message' => 'Р РѕР»СЊ СѓСЃРїРµС€РЅРѕ РЅР°Р·РЅР°С‡РµРЅР° РїРѕР»СЊР·РѕРІР°С‚РµР»СЋ'
        ]);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ ID РѕСЂРіР°РЅРёР·Р°С†РёРё РёР· Р·Р°РїСЂРѕСЃР°
     */
    protected function getOrganizationId(Request $request): int
    {
        $organizationId = $request->route('organization_id') 
            ?? $request->get('organization_id')
            ?? $request->input('organization_id')
            ?? $request->user()->current_organization_id;
            
        if (!$organizationId) {
            abort(400, 'Organization ID is required');
        }
        
        return (int) $organizationId;
    }
}
