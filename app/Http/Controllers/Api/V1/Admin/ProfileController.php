<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Profile\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            return response()->json([
                'success' => true,
                'data' => new UserResource($user)
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting admin profile', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении данных профиля.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            
            $profileData = $request->safe()->except(['avatar', 'remove_avatar', 'password']);
            
            // Обновляем основные поля
            foreach ($profileData as $field => $value) {
                $user->{$field} = $value;
            }

            // Сброс верификации email при его смене
            if ($request->has('email') && $user->email !== $request->email) {
                $user->email_verified_at = null;
            }

            // Обновление пароля если передан
            if ($request->filled('password')) {
                $user->password = bcrypt($request->password);
            }

            // Обработка аватара
            if ($request->boolean('remove_avatar')) {
                $user->deleteImage('avatar_path');
            } elseif ($request->hasFile('avatar')) {
                $user->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'private');
            }

            $user->save();
            $user->refresh();

            Log::info('Admin profile updated successfully', [
                'user_id' => $user->id,
                'updated_fields' => array_keys($profileData)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Профиль успешно обновлен.',
                'data' => new UserResource($user)
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating admin profile', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при обновлении профиля.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 