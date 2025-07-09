<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\User\UpdateProfileRequest; // Используем наш FormRequest
use App\Http\Resources\Api\V1\UserResource; // Предполагаем наличие этого ресурса
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; // Используем Request для доступа к hasFile и boolean
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    /**
     * Обновление профиля текущего аутентифицированного пользователя.
     *
     * @param UpdateProfileRequest $request
     * @return JsonResponse
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            // Обновляем основные поля профиля
            $profileData = $request->safe()->except(['avatar', 'remove_avatar']);
            $user->fill($profileData);

            // Сброс верификации email при его смене
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            // Обработка аватара
            $avatarActionSuccess = true; // Флаг для отслеживания успеха операции с аватаром
            if ($request->boolean('remove_avatar')) {
                Log::info('[ProfileController] Attempting to remove avatar.', ['user_id' => $user->id]);
                if (!$user->deleteImage('avatar_path')) {
                     Log::warning('[ProfileController] Failed to delete avatar from storage.', ['user_id' => $user->id]);
                     // Не блокируем обновление профиля, но логируем
                     $avatarActionSuccess = false; // Можно решить, является ли это критичной ошибкой
                }
            } elseif ($request->hasFile('avatar')) {
                Log::info('[ProfileController] Attempting to upload new avatar.', ['user_id' => $user->id]);
                if (!$user->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'private')) {
                    Log::error('[ProfileController] Failed to upload avatar.', ['user_id' => $user->id]);
                    // Возвращаем ошибку, так как пользователь явно хотел загрузить аватар
                    return response()->json([
                        'success' => false,
                        'message' => 'Не удалось загрузить аватар.'
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }

            // Сохраняем все изменения пользователя
            if (!$user->save()) {
                 Log::error('[ProfileController] Failed to save user model after update.', ['user_id' => $user->id]);
                 return response()->json([
                        'success' => false,
                        'message' => 'Не удалось сохранить изменения профиля.'
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            
            // Перезагружаем пользователя, чтобы ресурс получил актуальные данные (включая avatar_url)
            $user->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Профиль успешно обновлен.',
                'user' => new UserResource($user) // Возвращаем обновленные данные через ресурс
            ]);

        } catch (\Throwable $e) {
            Log::error('[ProfileController] Unexpected error during profile update.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Произошла внутренняя ошибка при обновлении профиля.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 