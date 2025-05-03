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

class UserController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
        // Middleware для проверки роли 'organization_owner' можно добавить здесь
        // или лучше на уровне маршрутов
        // $this->middleware('role:organization_owner'); // Пример кастомного middleware
    }

    /**
     * Список администраторов организации.
     */
    public function index(Request $request): JsonResponse
    {
        // Загружаем связь roles для каждого пользователя
        $admins = $this->userService->getAdminsForCurrentOrg($request)->load('roles');
        return AdminUserResource::collection($admins)->response();
    }

    /**
     * Создание нового администратора.
     * POST /api/v1/landing/users
     */
    public function store(StoreAdminRequest $request): Responsable
    {
        try {
            $admin = $this->userService->createAdmin($request->validated(), $request);
            // Загрузка связей для ресурса, если нужны для AdminUserResource
            // $admin->load('organizations'); // Если ресурс использует organizations.pivot.is_active

            return new SuccessCreationResponse(
                new AdminUserResource($admin),
                message: 'Administrator created successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
             // Это не должно произойти, т.к. есть StoreAdminRequest, но на всякий случай
             report($e);
             return new ErrorResponse(
                message: 'Validation failed',
                statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
                errors: $e->errors()
             );
        } catch (\Throwable $e) { // Ловим другие возможные ошибки (например, ошибка БД)
            report($e);
            return new ErrorResponse(
                message: 'Failed to create administrator. ' . $e->getMessage(),
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Информация о конкретном администраторе.
     * GET /api/v1/landing/users/{user}
     */
    public function show(Request $request, string $id): Responsable
    {
        try {
            // Передаем $request
            $admin = $this->userService->findAdminById((int)$id, $request);
            if (!$admin) {
                return new \App\Http\Responses\Api\V1\NotFoundResponse('Admin user not found');
            }
            // $admin->load('organizations'); // Раскомментировать, если ресурс использует данные организации
            // $admin->load('roles'); // Загружаем роли, если AdminUserResource их использует (уже добавлено в index)
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
     * Обновление данных администратора.
     * PUT /api/v1/landing/users/{user}
     */
    public function update(UpdateAdminRequest $request, string $id): Responsable
    {
        try {
            // Передаем $request как третий аргумент
            // И предполагаем, что updateAdmin теперь возвращает User
            $updatedAdmin = $this->userService->updateAdmin((int)$id, $request->validated(), $request);

            // Используем обновленного пользователя напрямую
            return new \App\Http\Responses\Api\V1\SuccessResourceResponse(
                new AdminUserResource($updatedAdmin), // $updatedAdmin->load('roles') - если нужно
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
     * Удаление администратора.
     * DELETE /api/v1/landing/users/{user}
     */
    public function destroy(Request $request, string $id): Responsable
    {
        try {
            // Передаем $request
            $success = $this->userService->deleteAdmin((int)$id, $request);
            // deleteAdmin возвращает bool, проверка остается
            if (!$success) {
                // Можно улучшить обработку ошибок из сервиса
                return new \App\Http\Responses\Api\V1\NotFoundResponse('Admin user not found or delete failed');
            }
            return new \App\Http\Responses\Api\V1\SuccessResourceResponse(null, statusCode: Response::HTTP_NO_CONTENT);
        } catch (\Throwable $e) {
             report($e);
             return new ErrorResponse('Failed to delete administrator. ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 