<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Repositories\UserRepository;
use App\Services\Billing\SubscriptionLimitsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Models\User;

/**
 * РљРѕРЅС‚СЂРѕР»Р»РµСЂ РґР»СЏ СѓРїСЂР°РІР»РµРЅРёСЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏРјРё СЃ РєР°СЃС‚РѕРјРЅС‹РјРё СЂРѕР»СЏРјРё
 */
class CustomUserManagementController extends Controller
{
    protected CustomRoleService $customRoleService;
    protected AuthorizationService $authService;
    protected RoleScanner $roleScanner;
    protected UserRepository $userRepository;
    protected SubscriptionLimitsService $subscriptionLimitsService;

    public function __construct(
        CustomRoleService $customRoleService,
        AuthorizationService $authService,
        RoleScanner $roleScanner,
        UserRepository $userRepository,
        SubscriptionLimitsService $subscriptionLimitsService
    ) {
        $this->customRoleService = $customRoleService;
        $this->authService = $authService;
        $this->roleScanner = $roleScanner;
        $this->userRepository = $userRepository;
        $this->subscriptionLimitsService = $subscriptionLimitsService;
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ СЃ РєР°СЃС‚РѕРјРЅС‹РјРё СЂРѕР»СЏРјРё
     */
    public function createUserWithCustomRoles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'custom_role_ids' => 'nullable|array',
            'custom_role_ids.*' => 'integer|exists:organization_custom_roles,id',
            'roles' => 'nullable|array',
            'roles.*' => 'string',
            'send_credentials' => 'sometimes|boolean'
        ]);

        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РљРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё РЅРµ РѕРїСЂРµРґРµР»РµРЅ'
            ], 400);
        }

        try {
            // РЎРѕР·РґР°РµРј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
            $data['password'] = Hash::make($data['password']);
            // $data['user_type'] = 'custom_role_user'; // РЈРґР°Р»РµРЅР° РІ РЅРѕРІРѕР№ СЃРёСЃС‚РµРјРµ Р°РІС‚РѕСЂРёР·Р°С†РёРё
            $data['current_organization_id'] = $organizationId;

            $user = $this->userRepository->create($data);

            // РџСЂРёРІСЏР·С‹РІР°РµРј Рє РѕСЂРіР°РЅРёР·Р°С†РёРё
            $this->userRepository->attachToOrganization($user->id, $organizationId, false, true);

            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);

            // РќР°Р·РЅР°С‡Р°РµРј РєР°СЃС‚РѕРјРЅС‹Рµ СЂРѕР»Рё С‡РµСЂРµР· РЅРѕРІСѓСЋ СЃРёСЃС‚РµРјСѓ
            if (!empty($data['custom_role_ids'])) {
                foreach ($data['custom_role_ids'] as $roleId) {
                    $role = \App\Domain\Authorization\Models\OrganizationCustomRole::findOrFail($roleId);
                    $this->customRoleService->assignRoleToUser($role, $user, $authContext);
                }
            }

            // РќР°Р·РЅР°С‡Р°РµРј СЃРёСЃС‚РµРјРЅС‹Рµ СЂРѕР»Рё
            if (!empty($data['roles'])) {
                foreach ($data['roles'] as $roleSlug) {
                    try {
                        $this->authService->assignRole($user, $roleSlug, $authContext);
                    } catch (\InvalidArgumentException $e) {
                        Log::warning("Skipping invalid system role: {$roleSlug}", ['error' => $e->getMessage()]);
                        // РњРѕР¶РЅРѕ РїСЂРµСЂРІР°С‚СЊ РёР»Рё РїСЂРѕРґРѕР»Р¶РёС‚СЊ. РџСЂРѕРґРѕР»Р¶РёРј.
                    }
                }
            }

            if (!$user->hasVerifiedEmail()) {
                try {
                    $user->sendEmailVerificationNotification();
                    Log::info('[CustomUserManagementController] Email verification sent to new user', [
                        'user_id' => $user->id,
                        'email' => $user->email
                    ]);
                } catch (\Exception $e) {
                    Log::error('[CustomUserManagementController] Failed to send email verification', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if ($data['send_credentials'] ?? false) {
                Log::info('User credentials need to be sent', ['user_id' => $user->id]);
            }

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'email_verified_at' => $user->email_verified_at,
                        'created_at' => $user->created_at
                    ]
                ],
                'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ СЃ РЅР°Р·РЅР°С‡РµРЅРЅС‹РјРё СЂРѕР»СЏРјРё'
            ], 201);

        } catch (ValidationException $e) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating user with custom roles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => trans_message('landing_users.admin_create_error')
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґРѕСЃС‚СѓРїРЅС‹Рµ СЂРѕР»Рё РґР»СЏ РѕСЂРіР°РЅРёР·Р°С†РёРё
     */
    public function getAvailableRoles(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РљРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё РЅРµ РѕРїСЂРµРґРµР»РµРЅ'
            ], 400);
        }

        try {
            // РЎРёСЃС‚РµРјРЅС‹Рµ СЂРѕР»Рё
            $systemRoles = $this->roleScanner->getAllRoles()->toArray();

            // РљР°СЃС‚РѕРјРЅС‹Рµ СЂРѕР»Рё РѕСЂРіР°РЅРёР·Р°С†РёРё
            $customRoles = collect([]);
            try {
                $customRoles = $this->customRoleService->getOrganizationRoles($organizationId);
            } catch (\Exception $e) {
                // Р•СЃР»Рё С‚Р°Р±Р»РёС†С‹ РЅРѕРІРѕР№ СЃРёСЃС‚РµРјС‹ РµС‰Рµ РЅРµ РіРѕС‚РѕРІС‹
                $customRoles = collect([]);
                Log::warning('Custom roles not available yet', ['error' => $e->getMessage()]);
            }

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'data' => [
                    'system_roles' => array_keys($systemRoles),
                    'custom_roles' => $customRoles->map(function($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'slug' => $role->slug,
                            'description' => $role->description,
                            'is_active' => $role->is_active
                        ];
                    })->values()->toArray(),
                    'organization_id' => $organizationId
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting available roles', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё РґРѕСЃС‚СѓРїРЅС‹С… СЂРѕР»РµР№'
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ РєР°СЃС‚РѕРјРЅС‹Рµ СЂРѕР»Рё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
     */
    public function updateUserCustomRoles(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'custom_role_ids' => 'required|array',
            'custom_role_ids.*' => 'integer|exists:organization_custom_roles,id'
        ]);

        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РљРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё РЅРµ РѕРїСЂРµРґРµР»РµРЅ'
            ], 400);
        }

        try {
            // РџРѕР»СѓС‡Р°РµРј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ РЅР°Р№РґРµРЅ'
                ], 404);
            }

            // РџСЂРѕРІРµСЂСЏРµРј РїСЂРёРЅР°РґР»РµР¶РЅРѕСЃС‚СЊ Рє РѕСЂРіР°РЅРёР·Р°С†РёРё
            if (!$user->organizations()->where('organization_user.organization_id', $organizationId)->exists()) {
                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ РїСЂРёРЅР°РґР»РµР¶РёС‚ Рє РґР°РЅРЅРѕР№ РѕСЂРіР°РЅРёР·Р°С†РёРё'
                ], 403);
            }

            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            $actor = $request->user();
            $this->customRoleService->syncUserRoles(
                $user,
                $data['custom_role_ids'],
                $authContext,
                $actor instanceof User ? $actor : null
            );

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'message' => 'Р РѕР»Рё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ СѓСЃРїРµС€РЅРѕ РѕР±РЅРѕРІР»РµРЅС‹'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating user custom roles', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РѕР±РЅРѕРІР»РµРЅРёРё СЂРѕР»РµР№ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ'
            ], 500);
        }
    }

    /**
     * РќР°Р·РЅР°С‡РёС‚СЊ РєР°СЃС‚РѕРјРЅСѓСЋ СЂРѕР»СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЋ
     */
    public function assignCustomRole(Request $request, int $userId, int $roleId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РљРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё РЅРµ РѕРїСЂРµРґРµР»РµРЅ'
            ], 400);
        }

        try {
            // РџРѕР»СѓС‡Р°РµРј СЂРѕР»СЊ Рё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РґР»СЏ РїРµСЂРµРґР°С‡Рё РІ СЃРµСЂРІРёСЃ
            $role = \App\Domain\Authorization\Models\OrganizationCustomRole::findOrFail($roleId);
            $user = $this->userRepository->find($userId);

            if (!$user) {
                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ РЅР°Р№РґРµРЅ'
                ], 404);
            }

            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            $this->customRoleService->assignRoleToUser($role, $user, $authContext);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'message' => 'Р РѕР»СЊ СѓСЃРїРµС€РЅРѕ РЅР°Р·РЅР°С‡РµРЅР° РїРѕР»СЊР·РѕРІР°С‚РµР»СЋ'
            ]);

        } catch (\Exception $e) {
            Log::error('Error assigning custom role', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РЅР°Р·РЅР°С‡РµРЅРёРё СЂРѕР»Рё'
            ], 500);
        }
    }

    /**
     * РћС‚РѕР·РІР°С‚СЊ РєР°СЃС‚РѕРјРЅСѓСЋ СЂРѕР»СЊ Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
     */
    public function unassignCustomRole(Request $request, int $userId, int $roleId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РљРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё РЅРµ РѕРїСЂРµРґРµР»РµРЅ'
            ], 400);
        }

        try {
            // РџРѕР»СѓС‡Р°РµРј СЂРѕР»СЊ Рё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РґР»СЏ РїРµСЂРµРґР°С‡Рё РІ СЃРµСЂРІРёСЃ
            $role = \App\Domain\Authorization\Models\OrganizationCustomRole::findOrFail($roleId);
            $user = $this->userRepository->find($userId);

            if (!$user) {
                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ РЅР°Р№РґРµРЅ'
                ], 404);
            }

            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            $revokedBy = $request->user();
            $this->authService->revokeRole(
                $user,
                $role->slug,
                $authContext,
                $revokedBy instanceof User ? $revokedBy : null
            );

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'message' => 'Р РѕР»СЊ СѓСЃРїРµС€РЅРѕ РѕС‚РѕР·РІР°РЅР° Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ'
            ]);

        } catch (\Exception $e) {
            Log::error('Error unassigning custom role', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РѕС‚Р·С‹РІРµ СЂРѕР»Рё'
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ Р»РёРјРёС‚С‹ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
     */
    public function getUserLimits(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $limits = $this->subscriptionLimitsService->getUserLimitsData($user);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'data' => $limits
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting user limits', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage()
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё Р»РёРјРёС‚РѕРІ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ'
            ], 500);
        }
    }
}
