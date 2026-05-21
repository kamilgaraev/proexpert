<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\User\UserService;
// --- РСЃРїСЂР°РІР»СЏРµРј РёРјРїРѕСЂС‚С‹ РґР»СЏ Request Рё Resource ---
use App\Http\Requests\Api\V1\Landing\User\StoreAdminPanelUserRequest;
use App\Http\Requests\Api\V1\Landing\AdminPanelUser\UpdateAdminPanelUserRequest;
use App\Http\Resources\Api\V1\Landing\AdminPanelUserResource;
use App\Http\Responses\Api\V1\SuccessCreationResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
use App\Http\Responses\Api\V1\SuccessResponse;
use App\Http\Responses\Api\V1\NotFoundResponse;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Exceptions\BusinessLogicException;

class AdminPanelUserController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Responsable
     */
    public function index(Request $request): Responsable
    {
        Log::info('[AdminPanelUserController@index] Method entered.', ['user_id' => Auth::id(), 'organization_id' => $request->attributes->get('current_organization_id')]);
        try {
            $users = $this->userService->getAdminPanelUsersForCurrentOrg($request);
            // Р—Р°РіСЂСѓР¶Р°РµРј СЂРѕР»Рё С‡РµСЂРµР· РЅРѕРІСѓСЋ СЃРёСЃС‚РµРјСѓ Р°РІС‚РѕСЂРёР·Р°С†РёРё
            try {
                $users->load('roleAssignments');
            } catch (\Exception $e) {
                Log::warning('[AdminPanelUserController] Cannot load roleAssignments - new auth tables not ready');
            }
            Log::info('[AdminPanelUserController@index] Users received from service.', ['count' => $users->count()]);
            
            // --- Р’Р Р•РњР•РќРќРћР• Р”РРђР“РќРћРЎРўРР§Р•РЎРљРћР• РР—РњР•РќР•РќРР• (РІРѕР·РІСЂР°С‰Р°РµРј РєР°Рє Р±С‹Р»Рѕ) ---
            // return \App\Http\Responses\LandingResponse::fromPayload(['success' => true, 'data' => ['users_count' => $users->count(), 'users_ids' => $users->pluck('id')]]);
            // return \App\Http\Responses\LandingResponse::fromPayload(['success' => true, 'message' => 'Reached controller, user count: ' . $users->count()]);
            // ---- РљРћРќР•Р¦ Р’Р Р•РњР•РќРќРћР“Рћ РР—РњР•РќР•РќРРЇ ----

            // Р’РѕР·РІСЂР°С‰Р°РµРј РѕСЂРёРіРёРЅР°Р»СЊРЅСѓСЋ Р»РѕРіРёРєСѓ
            return new SuccessResourceResponse(
                AdminPanelUserResource::collection($users)
            );
        } catch (\Throwable $e) {
            Log::error('[AdminPanelUserController@index] Exception caught in controller.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            throw $e; 
        }
    }

    /**
     * РЎРѕР·РґР°РЅРёРµ РЅРѕРІРѕРіРѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РґР»СЏ Р°РґРјРёРЅ-РїР°РЅРµР»Рё (web_admin, accountant).
     * POST /api/v1/landing/adminPanelUsers
     */
    public function store(StoreAdminPanelUserRequest $request): Responsable
    {
        Log::info('[AdminPanelUserController] store() method called - ENTRY POINT', [
            'user_id' => Auth::id(),
            'organization_id' => $request->attributes->get('current_organization_id'),
            'ip' => $request->ip()
        ]);

        try {
            Log::info('[AdminPanelUserController] РќР°С‡Р°Р»Рѕ СЃРѕР·РґР°РЅРёСЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ Р°РґРјРёРЅ-РїР°РЅРµР»Рё', [
                'data' => $request->validated(),
                'ip' => $request->ip()
            ]);
            
            $validatedData = $request->validated();
            $roleSlug = $validatedData['role_slug'];
            unset($validatedData['role_slug']);

            $user = $this->userService->createAdminPanelUser($validatedData, $roleSlug, $request);
            $user->load('roleAssignments');

            Log::info('[AdminPanelUserController] РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РґРјРёРЅ-РїР°РЅРµР»Рё СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ', [
                'user_id' => $user->id,
                'email' => $user->email, 
                'role' => $roleSlug
            ]);

            // Р’РѕР·РІСЂР°С‰Р°РµРј РїСЂР°РІРёР»СЊРЅС‹Р№ Responsable РѕС‚РІРµС‚
            return new SuccessCreationResponse(
                new AdminPanelUserResource($user),
                'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РґРјРёРЅ-РїР°РЅРµР»Рё СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ'
            );
         } catch (\Illuminate\Validation\ValidationException $e) {
             Log::error('[AdminPanelUserController] РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё', [
                 'errors' => $e->errors()
             ]);
             report($e);
             return new ErrorResponse('Validation failed', Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (BusinessLogicException $e) {
            // Р’РѕР·РІСЂР°С‰Р°РµРј Р±РёР·РЅРµСЃ-РѕС€РёР±РєРё СЃ РєРѕСЂСЂРµРєС‚РЅС‹Рј HTTP-СЃС‚Р°С‚СѓСЃРѕРј (РїРѕ РєРѕРґСѓ РёСЃРєР»СЋС‡РµРЅРёСЏ)
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : Response::HTTP_BAD_REQUEST;
            Log::warning('[AdminPanelUserController] Business logic error РїСЂРё СЃРѕР·РґР°РЅРёРё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ Р°РґРјРёРЅ-РїР°РЅРµР»Рё', [
                'message' => $e->getMessage(),
                'code' => $code,
            ]);
            return new ErrorResponse($e->getMessage(), $status);
        } catch (\Throwable $e) {
             Log::error('[AdminPanelUserController] РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ Р°РґРјРёРЅ-РїР°РЅРµР»Рё', [
                 'error' => $e->getMessage(),
                 'trace' => $e->getTraceAsString()
             ]);
             report($e);
             return new ErrorResponse('Failed to create admin panel user. ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param int $userId
     * @param Request $request
     * @return Responsable
     */
    public function show(int $userId, Request $request): Responsable
    {
        $user = $this->userService->findAdminPanelUserById($userId, $request);

        if (!$user) {
            return new NotFoundResponse('РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РґРјРёРЅ-РїР°РЅРµР»Рё РЅРµ РЅР°Р№РґРµРЅ.');
        }

        return new SuccessResourceResponse(new AdminPanelUserResource($user));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateAdminPanelUserRequest $request
     * @param int $userId
     * @return Responsable
     */
    public function update(UpdateAdminPanelUserRequest $request, int $userId): Responsable
    {
        $validatedData = $request->validated();
        $user = $this->userService->updateAdminPanelUser($userId, $validatedData, $request);

        return new SuccessResourceResponse(
            new AdminPanelUserResource($user),
            'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РґРјРёРЅ-РїР°РЅРµР»Рё СѓСЃРїРµС€РЅРѕ РѕР±РЅРѕРІР»РµРЅ.'
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $userId
     * @param Request $request
     * @return Responsable
     */
    public function destroy(int $userId, Request $request): Responsable
    {
        try {
            $deleted = $this->userService->deleteAdminPanelUser($userId, $request);

            if (!$deleted) {
                return new NotFoundResponse('РќРµ СѓРґР°Р»РѕСЃСЊ СѓРґР°Р»РёС‚СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ Р°РґРјРёРЅ-РїР°РЅРµР»Рё.');
            }

            return new SuccessResponse(message: 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РґРјРёРЅ-РїР°РЅРµР»Рё СѓСЃРїРµС€РЅРѕ СѓРґР°Р»РµРЅ.', statusCode: 204);
        } catch (BusinessLogicException $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : Response::HTTP_BAD_REQUEST;
            Log::warning('[AdminPanelUserController] Business logic error РїСЂРё СѓРґР°Р»РµРЅРёРё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ Р°РґРјРёРЅ-РїР°РЅРµР»Рё', [
                'message' => $e->getMessage(),
                'code' => $code,
            ]);
            return new ErrorResponse($e->getMessage(), $status);
        } catch (\Throwable $e) {
            Log::error('[AdminPanelUserController] РћС€РёР±РєР° СѓРґР°Р»РµРЅРёСЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ Р°РґРјРёРЅ-РїР°РЅРµР»Рё', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            report($e);
            return new ErrorResponse('Failed to delete admin panel user. ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Resend email verification for the specified user.
     *
     * @param int $userId
     * @param Request $request
     * @return Responsable
     */
    public function resendVerificationEmail(int $userId, Request $request): Responsable
    {
        try {
            $user = $this->userService->findAdminPanelUserById($userId, $request);

            if (!$user) {
                return new NotFoundResponse('РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РґРјРёРЅ-РїР°РЅРµР»Рё РЅРµ РЅР°Р№РґРµРЅ.');
            }

            if ($user->hasVerifiedEmail()) {
                return new ErrorResponse('Email СѓР¶Рµ РїРѕРґС‚РІРµСЂР¶РґРµРЅ', Response::HTTP_BAD_REQUEST);
            }

            $user->sendEmailVerificationNotification();

            Log::info('[AdminPanelUserController] Email verification resent', [
                'user_id' => $user->id,
                'email' => $user->email,
                'resent_by' => Auth::id()
            ]);

            return new SuccessResponse(
                message: 'РџРёСЃСЊРјРѕ СЃ РїРѕРґС‚РІРµСЂР¶РґРµРЅРёРµРј email РѕС‚РїСЂР°РІР»РµРЅРѕ РїРѕРІС‚РѕСЂРЅРѕ',
                statusCode: 200
            );
        } catch (\Throwable $e) {
            Log::error('[AdminPanelUserController] РћС€РёР±РєР° РїСЂРё РїРµСЂРµРѕС‚РїСЂР°РІРєРµ РїРёСЃСЊРјР° РїРѕРґС‚РІРµСЂР¶РґРµРЅРёСЏ', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            report($e);
            return new ErrorResponse('Failed to resend verification email. ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 
