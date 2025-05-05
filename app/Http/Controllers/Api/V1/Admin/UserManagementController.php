<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\User\UserService;
use App\Http\Requests\Api\V1\Admin\UserManagement\StoreForemanRequest;
use App\Http\Requests\Api\V1\Admin\UserManagement\UpdateForemanRequest;
use App\Http\Resources\Api\V1\Admin\User\ForemanUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;

class UserManagementController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
        // Применяем middleware для проверки доступа к админ-панели ко всем методам контроллера
        $this->middleware('can:access-admin-panel');
        // Убираем middleware для проверки прав на управление прорабами отсюда
        // $this->middleware('can:manage-foremen');
    }

    // Получить список прорабов
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        // Добавляем проверку прав здесь
        $this->authorize('manage-foremen');

        $perPage = $request->query('per_page', 15); // Получаем параметр пагинации из запроса
        $foremenPaginator = $this->userService->getForemenForCurrentOrg($request, (int)$perPage);
        
        // Возвращаем коллекцию ресурсов, Laravel автоматически обработает пагинацию
        return ForemanUserResource::collection($foremenPaginator);
    }

    // Создать нового прораба
    public function store(StoreForemanRequest $request): ForemanUserResource
    {
        // TODO: Пагинация, фильтрация, API Resource
        $foremen = $this->userService->getForemenForCurrentOrg($request);
        // В ресурсе ForemanUserResource используется $this->whenPivotLoaded, 
        // нужно убедиться, что сервис/репозиторий загружает эти данные
        // $foremen->load('organizations'); // Пример загрузки pivot данных
        $foreman = $this->userService->createForeman($request->validated(), $request);
        if (!$foreman) {
            // Возвращаем ошибку 500, если сервис вернул false (например, роль не найдена)
            abort(500, 'Failed to create foreman. Required role might be missing.');
        }
        // Загружаем pivot данные для ресурса
        $foreman->load('organizations'); 
        return new ForemanUserResource($foreman);
    }

    // Показать конкретного прораба
    public function show(Request $request, string $id): ForemanUserResource | JsonResponse
    {
        $foreman = $this->userService->findForemanById((int)$id, $request);
        if (!$foreman) {
            return response()->json(['message' => 'Foreman not found'], 404);
        }
        // TODO: Убедиться, что сервис/репозиторий проверяет принадлежность к организации
        // Загружаем pivot данные для ресурса
        $foreman->load('organizations');
        return new ForemanUserResource($foreman);
    }

    // Обновить данные прораба
    public function update(UpdateForemanRequest $request, string $id): ForemanUserResource | JsonResponse
    {
        $success = $this->userService->updateForeman((int)$id, $request->validated(), $request);
        if (!$success) {
            return response()->json(['message' => 'Foreman not found or update failed'], 404);
        }
        $foreman = $this->userService->findForemanById((int)$id, $request);
        // Загружаем pivot данные для ресурса
        $foreman->load('organizations');
        return new ForemanUserResource($foreman);
    }

    // Удалить (деактивировать?) прораба
    public function destroy(Request $request, string $id): JsonResponse
    {
        $success = $this->userService->deleteForeman((int)$id, $request);
        if (!$success) {
            return response()->json(['message' => 'Foreman not found or delete failed'], 404);
        }
        return response()->json(null, 204);
    }

    /**
     * Блокировка прораба.
     */
    public function block(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->userService->blockForeman((int)$id, $request);
            if (!$success) {
                 // UserService должен был выбросить исключение, но на всякий случай
                return response()->json(['message' => 'Failed to block foreman.'], 500);
            }
            return response()->json(['message' => 'Foreman blocked successfully.']);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error blocking foreman', ['exception' => $e]);
            return response()->json(['message' => 'Internal server error while blocking foreman.'], 500);
        }
    }

    /**
     * Разблокировка прораба.
     */
    public function unblock(Request $request, string $id): JsonResponse
    {
         try {
            $success = $this->userService->unblockForeman((int)$id, $request);
            if (!$success) {
                // UserService должен был выбросить исключение, но на всякий случай
                return response()->json(['message' => 'Failed to unblock foreman.'], 500);
            }
            return response()->json(['message' => 'Foreman unblocked successfully.']);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error unblocking foreman', ['exception' => $e]);
            return response()->json(['message' => 'Internal server error while unblocking foreman.'], 500);
        }
    }
} 