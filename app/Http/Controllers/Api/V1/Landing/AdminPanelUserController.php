<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\User\UserService;
// --- Исправляем импорты для Request и Resource ---
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
        $users = $this->userService->getAdminPanelUsersForCurrentOrg($request);
        return new SuccessResourceResponse(
            AdminPanelUserResource::collection($users)
        );
    }

    /**
     * Создание нового пользователя для админ-панели (web_admin, accountant).
     * POST /api/v1/landing/adminPanelUsers
     */
    public function store(StoreAdminPanelUserRequest $request): Responsable
    {
        try {
            Log::info('[AdminPanelUserController] Начало создания пользователя админ-панели', [
                'data' => $request->validated(),
                'ip' => $request->ip()
            ]);
            
            $validatedData = $request->validated();
            $roleSlug = $validatedData['role_slug'];
            unset($validatedData['role_slug']);

            $user = $this->userService->createAdminPanelUser($validatedData, $roleSlug, $request);

            Log::info('[AdminPanelUserController] Пользователь админ-панели успешно создан', [
                'user_id' => $user->id,
                'email' => $user->email, 
                'role' => $roleSlug
            ]);

            // Возвращаем правильный Responsable ответ
            return new SuccessCreationResponse(
                new AdminPanelUserResource($user->load('roles')), // Используем ресурс и загружаем роли
                'Admin panel user created successfully'
            );
         } catch (\Illuminate\Validation\ValidationException $e) {
             Log::error('[AdminPanelUserController] Ошибка валидации', [
                 'errors' => $e->errors()
             ]);
             report($e);
             return new ErrorResponse('Validation failed', Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (\Throwable $e) {
             Log::error('[AdminPanelUserController] Ошибка создания пользователя админ-панели', [
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
            return new NotFoundResponse('Пользователь админ-панели не найден.');
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
            'Пользователь админ-панели успешно обновлен.'
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
        $deleted = $this->userService->deleteAdminPanelUser($userId, $request);

        if (!$deleted) {
            // Теоретически, findAdminPanelUserById внутри deleteAdminPanelUser должен выбросить исключение 404/403 раньше.
            // Но на всякий случай.
            return new NotFoundResponse('Не удалось удалить пользователя админ-панели.');
        }

        return new SuccessResponse(message: 'Пользователь админ-панели успешно удален.', statusCode: 204); // 204 No Content
    }
} 