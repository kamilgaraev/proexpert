<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\User\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            $profileData = $request->safe()->except(['avatar', 'remove_avatar']);
            $user->fill($profileData);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            if ($request->boolean('remove_avatar')) {
                Log::info('[ProfileController] Attempting to remove avatar.', ['user_id' => $user->id]);

                if (!$user->deleteImage('avatar_path')) {
                    Log::warning('[ProfileController] Failed to delete avatar from storage.', ['user_id' => $user->id]);
                }
            } elseif ($request->hasFile('avatar')) {
                Log::info('[ProfileController] Attempting to upload new avatar.', ['user_id' => $user->id]);

                if (!$user->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'public')) {
                    Log::error('[ProfileController] Failed to upload avatar.', ['user_id' => $user->id]);

                    return LandingResponse::error(
                        trans_message('landing.profile.avatar_upload_error'),
                        Response::HTTP_INTERNAL_SERVER_ERROR
                    );
                }
            }

            if (!$user->save()) {
                Log::error('[ProfileController] Failed to save user model after update.', ['user_id' => $user->id]);

                return LandingResponse::error(
                    trans_message('landing.profile.save_error'),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            $user->refresh();

            return LandingResponse::success([
                'user' => new UserResource($user),
            ], trans_message('landing.profile.updated'));
        } catch (\Throwable $e) {
            Log::error('[ProfileController] Unexpected error during profile update.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error(
                trans_message('landing.profile.update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
