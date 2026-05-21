<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\User\UserService;
use App\Http\Requests\Api\V1\Landing\User\StoreAdminRequest;
use App\Http\Requests\Api\V1\Landing\User\UpdateAdminRequest;
use App\Http\Resources\Api\V1\Landing\AdminUserResource;
use App\Http\Responses\Api\V1\SuccessCreationResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use App\Exceptions\BusinessLogicException;

class UserController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
        // Middleware РґР»СЏ РїСЂРѕРІРµСЂРєРё СЂРѕР»Рё 'organization_owner' РјРѕР¶РЅРѕ РґРѕР±Р°РІРёС‚СЊ Р·РґРµСЃСЊ
        // РёР»Рё Р»СѓС‡С€Рµ РЅР° СѓСЂРѕРІРЅРµ РјР°СЂС€СЂСѓС‚РѕРІ
        // $this->middleware('role:organization_owner'); // РџСЂРёРјРµСЂ РєР°СЃС‚РѕРјРЅРѕРіРѕ middleware
    }

    /**
     * РЎРїРёСЃРѕРє Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂРѕРІ РѕСЂРіР°РЅРёР·Р°С†РёРё.
     */
    public function index(Request $request): JsonResponse
    {
        // Р—Р°РіСЂСѓР¶Р°РµРј СЂРѕР»Рё С‡РµСЂРµР· РЅРѕРІСѓСЋ СЃРёСЃС‚РµРјСѓ Р°РІС‚РѕСЂРёР·Р°С†РёРё
        $admins = $this->userService->getAdminsForCurrentOrg($request);
        try {
            $admins->load('roleAssignments');
        } catch (\Exception $e) {
            // РўР°Р±Р»РёС†С‹ РЅРѕРІРѕР№ СЃРёСЃС‚РµРјС‹ РµС‰Рµ РЅРµ РіРѕС‚РѕРІС‹
        }
        return AdminUserResource::collection($admins)->response();
    }

    /**
     * РЎРѕР·РґР°РЅРёРµ РЅРѕРІРѕРіРѕ Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°.
     * POST /api/v1/landing/users
     */
    public function store(StoreAdminRequest $request): Responsable
    {
        try {
            $admin = $this->userService->createAdmin($request->validated(), $request);
            // Р—Р°РіСЂСѓР·РєР° СЃРІСЏР·РµР№ РґР»СЏ СЂРµСЃСѓСЂСЃР°, РµСЃР»Рё РЅСѓР¶РЅС‹ РґР»СЏ AdminUserResource
            // $admin->load('organizations'); // Р•СЃР»Рё СЂРµСЃСѓСЂСЃ РёСЃРїРѕР»СЊР·СѓРµС‚ organizations.pivot.is_active

            return new SuccessCreationResponse(
                new AdminUserResource($admin),
                message: 'Administrator created successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
             // Р­С‚Рѕ РЅРµ РґРѕР»Р¶РЅРѕ РїСЂРѕРёР·РѕР№С‚Рё, С‚.Рє. РµСЃС‚СЊ StoreAdminRequest, РЅРѕ РЅР° РІСЃСЏРєРёР№ СЃР»СѓС‡Р°Р№
             report($e);
             return new ErrorResponse(
                message: 'Validation failed',
                statusCode: Response::HTTP_UNPROCESSABLE_ENTITY
             );
        } catch (\Throwable $e) { // Р›РѕРІРёРј РґСЂСѓРіРёРµ РІРѕР·РјРѕР¶РЅС‹Рµ РѕС€РёР±РєРё (РЅР°РїСЂРёРјРµСЂ, РѕС€РёР±РєР° Р‘Р”)
            report($e);
            return new ErrorResponse(
                message: 'Failed to create administrator. ' . $e->getMessage(),
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * РРЅС„РѕСЂРјР°С†РёСЏ Рѕ РєРѕРЅРєСЂРµС‚РЅРѕРј Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂРµ.
     * GET /api/v1/landing/users/{user}
     */
    public function show(Request $request, string $id): Responsable
    {
        try {
            // РџРµСЂРµРґР°РµРј $request
            $admin = $this->userService->findAdminById((int)$id, $request);
            if (!$admin) {
                return new \App\Http\Responses\Api\V1\NotFoundResponse('Admin user not found');
            }
            // $admin->load('organizations'); // Р Р°СЃРєРѕРјРјРµРЅС‚РёСЂРѕРІР°С‚СЊ, РµСЃР»Рё СЂРµСЃСѓСЂСЃ РёСЃРїРѕР»СЊР·СѓРµС‚ РґР°РЅРЅС‹Рµ РѕСЂРіР°РЅРёР·Р°С†РёРё
            // Р РѕР»Рё С‚РµРїРµСЂСЊ Р·Р°РіСЂСѓР¶Р°СЋС‚СЃСЏ С‡РµСЂРµР· РЅРѕРІСѓСЋ СЃРёСЃС‚РµРјСѓ Р°РІС‚РѕСЂРёР·Р°С†РёРё РїСЂРё РЅРµРѕР±С…РѕРґРёРјРѕСЃС‚Рё
            return new \App\Http\Responses\Api\V1\SuccessResourceResponse(new AdminUserResource($admin));
        } catch (\Throwable $e) {
             report($e);
             return new ErrorResponse(
                message: 'Failed to retrieve administrator info. ' . $e->getMessage(),
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * РћР±РЅРѕРІР»РµРЅРёРµ РґР°РЅРЅС‹С… Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°.
     * PUT /api/v1/landing/users/{user}
     */
    public function update(UpdateAdminRequest $request, string $id): Responsable
    {
        try {
            // РџРµСЂРµРґР°РµРј $request РєР°Рє С‚СЂРµС‚РёР№ Р°СЂРіСѓРјРµРЅС‚
            // Р РїСЂРµРґРїРѕР»Р°РіР°РµРј, С‡С‚Рѕ updateAdmin С‚РµРїРµСЂСЊ РІРѕР·РІСЂР°С‰Р°РµС‚ User
            $updatedAdmin = $this->userService->updateAdmin((int)$id, $request->validated(), $request);

            // РСЃРїРѕР»СЊР·СѓРµРј РѕР±РЅРѕРІР»РµРЅРЅРѕРіРѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РЅР°РїСЂСЏРјСѓСЋ
            return new \App\Http\Responses\Api\V1\SuccessResourceResponse(
                new AdminUserResource($updatedAdmin), // roleAssignments Р·Р°РіСЂСѓР¶Р°СЋС‚СЃСЏ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё РїСЂРё РЅРµРѕР±С…РѕРґРёРјРѕСЃС‚Рё
                message: 'Administrator updated successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
             report($e);
             return new ErrorResponse('Validation failed', Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (\Throwable $e) {
             report($e);
             return new ErrorResponse('Failed to update administrator. ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * РЈРґР°Р»РµРЅРёРµ Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°.
     * DELETE /api/v1/landing/users/{user}
     */
    public function destroy(Request $request, string $id): Responsable
    {
        try {
            // РџРµСЂРµРґР°РµРј $request
            $success = $this->userService->deleteAdmin((int)$id, $request);
            // deleteAdmin РІРѕР·РІСЂР°С‰Р°РµС‚ bool, РїСЂРѕРІРµСЂРєР° РѕСЃС‚Р°РµС‚СЃСЏ
            if (!$success) {
                // РњРѕР¶РЅРѕ СѓР»СѓС‡С€РёС‚СЊ РѕР±СЂР°Р±РѕС‚РєСѓ РѕС€РёР±РѕРє РёР· СЃРµСЂРІРёСЃР°
                return new \App\Http\Responses\Api\V1\NotFoundResponse('Admin user not found or delete failed');
            }
            return new \App\Http\Responses\Api\V1\SuccessResourceResponse(null, statusCode: Response::HTTP_NO_CONTENT);
        } catch (\Throwable $e) {
             report($e);
             return new ErrorResponse('Failed to delete administrator. ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * РЎРїРёСЃРѕРє РІСЃРµС… РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё, РёРјРµСЋС‰РёС… РґРѕСЃС‚СѓРї Рє Р°РґРјРёРЅ-РїР°РЅРµР»Рё.
     * GET /api/v1/landing/admin-panel-users 
     */
    public function adminPanelUsersIndex(Request $request): JsonResponse
    {
        try {
            $users = $this->userService->getAllAdminPanelUsersForCurrentOrg($request);
            try {
                $users->load('roleAssignments');
            } catch (\Exception $e) {
                // РўР°Р±Р»РёС†С‹ РЅРѕРІРѕР№ СЃРёСЃС‚РµРјС‹ РµС‰Рµ РЅРµ РіРѕС‚РѕРІС‹
            }
            // РСЃРїРѕР»СЊР·СѓРµРј С‚РѕС‚ Р¶Рµ СЂРµСЃСѓСЂСЃ, С‡С‚Рѕ Рё РґР»СЏ index, РµСЃР»Рё РѕРЅ РїРѕРґС…РѕРґРёС‚
            return AdminUserResource::collection($users)->response(); 
        } catch (BusinessLogicException $e) {
            // РџРµСЂРµС…РІР°С‚С‹РІР°РµРј РѕС€РёР±РєРё Р±РёР·РЅРµСЃ-Р»РѕРіРёРєРё (РЅР°РїСЂРёРјРµСЂ, РЅРµС‚ РїСЂР°РІ РґРѕСЃС‚СѓРїР°)
            return \App\Http\Responses\LandingResponse::fromPayload(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            report($e);
            return \App\Http\Responses\LandingResponse::fromPayload(['success' => false, 'message' => 'Failed to retrieve admin panel users.'], 500);
        }
    }
} 