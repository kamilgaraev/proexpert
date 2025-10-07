<?php

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Notifications\Services\PreferenceManager;
use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class NotificationPreferencesController extends Controller
{
    protected PreferenceManager $preferenceManager;

    public function __construct(PreferenceManager $preferenceManager)
    {
        $this->preferenceManager = $preferenceManager;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->query('organization_id');

        $preferences = NotificationPreference::forUser($user->id)
            ->when($organizationId, fn($q) => $q->forOrganization($organizationId))
            ->get();

        $types = config('notifications.types', []);
        
        $result = [];
        foreach ($types as $typeKey => $typeConfig) {
            $preference = $preferences->firstWhere('notification_type', $typeKey);
            
            $result[] = [
                'notification_type' => $typeKey,
                'name' => $typeConfig['name'],
                'description' => $typeConfig['description'],
                'mandatory' => $typeConfig['mandatory'],
                'user_customizable' => $typeConfig['user_customizable'],
                'default_channels' => $typeConfig['default_channels'],
                'enabled_channels' => $preference ? $preference->enabled_channels : $typeConfig['default_channels'],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notification_type' => 'required|string',
            'enabled_channels' => 'required|array',
            'enabled_channels.*' => 'in:email,telegram,in_app,websocket',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $notificationType = $request->notification_type;
        $channels = $request->enabled_channels;
        $organizationId = $request->organization_id;

        $typeConfig = config("notifications.types.{$notificationType}");

        if (!$typeConfig) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid notification type',
            ], 400);
        }

        if ($typeConfig['mandatory'] || !$typeConfig['user_customizable']) {
            return response()->json([
                'success' => false,
                'message' => 'This notification type cannot be customized',
            ], 403);
        }

        $preference = $this->preferenceManager->updatePreferences(
            $user,
            $notificationType,
            $channels,
            $organizationId
        );

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated',
            'data' => $preference,
        ]);
    }

    public function updateQuietHours(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notification_type' => 'required|string',
            'quiet_hours_start' => 'nullable|date_format:H:i',
            'quiet_hours_end' => 'nullable|date_format:H:i',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $preference = NotificationPreference::updateOrCreate(
            [
                'user_id' => $user->id,
                'organization_id' => $request->organization_id,
                'notification_type' => $request->notification_type,
            ],
            [
                'quiet_hours_start' => $request->quiet_hours_start,
                'quiet_hours_end' => $request->quiet_hours_end,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Quiet hours updated',
            'data' => $preference,
        ]);
    }
}

