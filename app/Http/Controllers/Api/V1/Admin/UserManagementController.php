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
use Illuminate\Support\Facades\Gate;

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
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection | JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $foremenPaginator = $this->userService->getForemenForCurrentOrg($request, (int)$perPage);
            return ForemanUserResource::collection($foremenPaginator);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@index', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении списка прорабов.',
            ], 500);
        }
    }

    // Создать нового прораба
    public function store(StoreForemanRequest $request): ForemanUserResource | JsonResponse
    {
        try {
            // Сначала создаем пользователя через сервис
            // $request->validated() уже будет содержать phone и position, если они были переданы
            $foreman = $this->userService->createForeman($request->validated(), $request);

            // Обработка загрузки аватара, если файл был передан
            if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
                // Используем метод uploadImage из трейта HasImages
                // 'avatar_path' - имя атрибута в модели User для хранения пути к файлу
                // 'avatars' - директория в S3 (или другом сконфигурированном диске)
                // 'public' - видимость файла
                if ($foreman->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'public')) {
                    $foreman->save(); // Сохраняем модель User с обновленным avatar_path
                } else {
                    // Логируем ошибку, если загрузка не удалась, но не прерываем процесс,
                    // так как пользователь уже создан. Можно добавить более сложную логику отката.
                    Log::error('[UserManagementController@store] Failed to upload avatar for user.', ['user_id' => $foreman->id]);
                }
            }

            // Загружаем pivot данные для ресурса, если пользователь успешно создан
            $foreman->load('organizations'); 
            return new ForemanUserResource($foreman);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            // Ошибка abort(500, ...) из сервиса может быть поймана здесь как HttpException
            // или другая общая ошибка, если сервис вернул null/false и контроллер вызвал abort.
            // Мы убрали abort(500) из store, но UserService->createForeman может все еще выбрасывать BusinessLogicException 500.
            Log::error('Error in UserManagementController@store', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при создании прораба.',
            ], 500);
        }
    }

    // Показать конкретного прораба
    public function show(Request $request, string $id): ForemanUserResource | JsonResponse
    {
        try {
            $foreman = $this->userService->findForemanById((int)$id, $request);
            if (!$foreman) {
                // Это специфичный случай "не найдено", который не является BusinessLogicException от сервиса
                return response()->json([
                    'success' => false,
                    'message' => 'Прораб не найден.'
                ], 404);
            }
            $foreman->load('organizations');
            return new ForemanUserResource($foreman);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@show', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении данных прораба.',
            ], 500);
        }
    }

    // Обновить данные прораба
    public function update(UpdateForemanRequest $request, string $id): ForemanUserResource | JsonResponse
    {
        try {
            // Сначала получаем пользователя, затем обновляем его данные из $request->validated()
            // $this->userService->updateForeman должен возвращать модель User
            $foreman = $this->userService->updateForeman((int)$id, $request->validated(), $request);
            
            if (!$foreman) {
                 return response()->json(['success' => false, 'message' => 'Прораб не найден или не удалось обновить основные данные.'], 404);
            }

            $avatarChanged = false;

            // Обработка удаления аватара
            if ($request->has('remove_avatar') && $request->boolean('remove_avatar')) {
                if ($foreman->deleteImage('avatar_path')) {
                    $avatarChanged = true;
                } else {
                    Log::warning('[UserManagementController@update] Failed to delete avatar from storage for user.', ['user_id' => $foreman->id]);
                    // Можно решить, является ли это критической ошибкой и возвращать JsonResponse с ошибкой
                }
            } 
            // Загрузка нового аватара (только если не было запроса на удаление)
            // или если remove_avatar = false и пришел новый файл
            else if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
                if ($foreman->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'public')) {
                    $avatarChanged = true;
                } else {
                    Log::error('[UserManagementController@update] Failed to upload new avatar for user.', ['user_id' => $foreman->id]);
                    // Можно вернуть ошибку, если загрузка аватара критична
                    return response()->json(['success' => false, 'message' => 'Не удалось загрузить новый аватар.'], 500);
                }
            }

            // Если аватар менялся, сохраняем модель
            if ($avatarChanged) {
                $foreman->save();
            }

            // Загружаем pivot данные для ресурса
            $foreman->load('organizations');
            return new ForemanUserResource($foreman);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@update', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при обновлении данных прораба.',
            ], 500);
        }
    }

    // Удалить (деактивировать?) прораба
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->userService->deleteForeman((int)$id, $request);
            return response()->json([
                'success' => true,
                'message' => 'Прораб успешно удален/деактивирован.'
            ], 200); // Или 204, если не возвращаем тело
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@destroy', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при удалении прораба.',
            ], 500);
        }
    }

    /**
     * Блокировка прораба.
     */
    public function block(Request $request, string $id): JsonResponse
    {
        try {
            $this->userService->blockForeman((int)$id, $request);
            return response()->json([
                'success' => true,
                'message' => 'Прораб успешно заблокирован.'
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@block', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при блокировке прораба.',
                // 'debug_message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Разблокировка прораба.
     */
    public function unblock(Request $request, string $id): JsonResponse
    {
         try {
            $this->userService->unblockForeman((int)$id, $request);
            return response()->json([
                'success' => true,
                'message' => 'Прораб успешно разблокирован.'
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@unblock', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при разблокировке прораба.',
                // 'debug_message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
} 