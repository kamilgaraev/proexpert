<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\User\UpdateProfileRequest; // РСЃРїРѕР»СЊР·СѓРµРј РЅР°С€ FormRequest
use App\Http\Resources\Api\V1\UserResource; // РџСЂРµРґРїРѕР»Р°РіР°РµРј РЅР°Р»РёС‡РёРµ СЌС‚РѕРіРѕ СЂРµСЃСѓСЂСЃР°
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; // РСЃРїРѕР»СЊР·СѓРµРј Request РґР»СЏ РґРѕСЃС‚СѓРїР° Рє hasFile Рё boolean
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    /**
     * РћР±РЅРѕРІР»РµРЅРёРµ РїСЂРѕС„РёР»СЏ С‚РµРєСѓС‰РµРіРѕ Р°СѓС‚РµРЅС‚РёС„РёС†РёСЂРѕРІР°РЅРЅРѕРіРѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ.
     *
     * @param UpdateProfileRequest $request
     * @return JsonResponse
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            // РћР±РЅРѕРІР»СЏРµРј РѕСЃРЅРѕРІРЅС‹Рµ РїРѕР»СЏ РїСЂРѕС„РёР»СЏ
            $profileData = $request->safe()->except(['avatar', 'remove_avatar']);
            $user->fill($profileData);

            // РЎР±СЂРѕСЃ РІРµСЂРёС„РёРєР°С†РёРё email РїСЂРё РµРіРѕ СЃРјРµРЅРµ
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            // РћР±СЂР°Р±РѕС‚РєР° Р°РІР°С‚Р°СЂР°
            $avatarActionSuccess = true; // Р¤Р»Р°Рі РґР»СЏ РѕС‚СЃР»РµР¶РёРІР°РЅРёСЏ СѓСЃРїРµС…Р° РѕРїРµСЂР°С†РёРё СЃ Р°РІР°С‚Р°СЂРѕРј
            if ($request->boolean('remove_avatar')) {
                Log::info('[ProfileController] Attempting to remove avatar.', ['user_id' => $user->id]);
                if (!$user->deleteImage('avatar_path')) {
                     Log::warning('[ProfileController] Failed to delete avatar from storage.', ['user_id' => $user->id]);
                     // РќРµ Р±Р»РѕРєРёСЂСѓРµРј РѕР±РЅРѕРІР»РµРЅРёРµ РїСЂРѕС„РёР»СЏ, РЅРѕ Р»РѕРіРёСЂСѓРµРј
                     $avatarActionSuccess = false; // РњРѕР¶РЅРѕ СЂРµС€РёС‚СЊ, СЏРІР»СЏРµС‚СЃСЏ Р»Рё СЌС‚Рѕ РєСЂРёС‚РёС‡РЅРѕР№ РѕС€РёР±РєРѕР№
                }
            } elseif ($request->hasFile('avatar')) {
                Log::info('[ProfileController] Attempting to upload new avatar.', ['user_id' => $user->id]);
                if (!$user->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'public')) {
                    Log::error('[ProfileController] Failed to upload avatar.', ['user_id' => $user->id]);
                    // Р’РѕР·РІСЂР°С‰Р°РµРј РѕС€РёР±РєСѓ, С‚Р°Рє РєР°Рє РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ СЏРІРЅРѕ С…РѕС‚РµР» Р·Р°РіСЂСѓР·РёС‚СЊ Р°РІР°С‚Р°СЂ
                    return \App\Http\Responses\LandingResponse::fromPayload([
                        'success' => false,
                        'message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ Р·Р°РіСЂСѓР·РёС‚СЊ Р°РІР°С‚Р°СЂ.'
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }

            // РЎРѕС…СЂР°РЅСЏРµРј РІСЃРµ РёР·РјРµРЅРµРЅРёСЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
            if (!$user->save()) {
                 Log::error('[ProfileController] Failed to save user model after update.', ['user_id' => $user->id]);
                 return \App\Http\Responses\LandingResponse::fromPayload([
                        'success' => false,
                        'message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕС…СЂР°РЅРёС‚СЊ РёР·РјРµРЅРµРЅРёСЏ РїСЂРѕС„РёР»СЏ.'
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            
            // РџРµСЂРµР·Р°РіСЂСѓР¶Р°РµРј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ, С‡С‚РѕР±С‹ СЂРµСЃСѓСЂСЃ РїРѕР»СѓС‡РёР» Р°РєС‚СѓР°Р»СЊРЅС‹Рµ РґР°РЅРЅС‹Рµ (РІРєР»СЋС‡Р°СЏ avatar_url)
            $user->refresh();

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'message' => 'РџСЂРѕС„РёР»СЊ СѓСЃРїРµС€РЅРѕ РѕР±РЅРѕРІР»РµРЅ.',
                'user' => new UserResource($user) // Р’РѕР·РІСЂР°С‰Р°РµРј РѕР±РЅРѕРІР»РµРЅРЅС‹Рµ РґР°РЅРЅС‹Рµ С‡РµСЂРµР· СЂРµСЃСѓСЂСЃ
            ]);

        } catch (\Throwable $e) {
            Log::error('[ProfileController] Unexpected error during profile update.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РџСЂРѕРёР·РѕС€Р»Р° РІРЅСѓС‚СЂРµРЅРЅСЏСЏ РѕС€РёР±РєР° РїСЂРё РѕР±РЅРѕРІР»РµРЅРёРё РїСЂРѕС„РёР»СЏ.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 