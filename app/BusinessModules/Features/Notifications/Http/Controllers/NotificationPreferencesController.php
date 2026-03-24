<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use App\BusinessModules\Features\Notifications\Services\PreferenceManager;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class NotificationPreferencesController extends Controller
{
    public function __construct(
        protected PreferenceManager $preferenceManager
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $request->query('organization_id');

            $preferences = NotificationPreference::forUser($user->id)
                ->when($organizationId, fn ($query) => $query->forOrganization($organizationId))
                ->get();

            $result = [];
            foreach (config('notifications.types', []) as $typeKey => $typeConfig) {
                $preference = $preferences->firstWhere('notification_type', $typeKey);

                $result[] = [
                    'notification_type' => $typeKey,
                    'name' => $typeConfig['name'],
                    'description' => $typeConfig['description'],
                    'mandatory' => $typeConfig['mandatory'],
                    'user_customizable' => $typeConfig['user_customizable'],
                    'default_channels' => $typeConfig['default_channels'],
                    'enabled_channels' => $preference
                        ? $preference->enabled_channels
                        : $typeConfig['default_channels'],
                ];
            }

            return AdminResponse::success($result, trans_message('notifications.preferences_loaded'));
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'index',
                $e,
                $request,
                trans_message('notifications.preferences_load_error')
            );
        }
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
            return AdminResponse::error(
                trans_message('notifications.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $validator->errors()
            );
        }

        try {
            $typeConfig = config('notifications.types.' . $request->notification_type);

            if (!$typeConfig) {
                return AdminResponse::error(trans_message('notifications.invalid_type'), Response::HTTP_BAD_REQUEST);
            }

            if ($typeConfig['mandatory'] || !$typeConfig['user_customizable']) {
                return AdminResponse::error(
                    trans_message('notifications.not_customizable'),
                    Response::HTTP_FORBIDDEN
                );
            }

            $preference = $this->preferenceManager->updatePreferences(
                $request->user(),
                (string) $request->notification_type,
                (array) $request->enabled_channels,
                $request->organization_id ? (int) $request->organization_id : null
            );

            return AdminResponse::success(
                $preference,
                trans_message('notifications.preferences_updated')
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'update',
                $e,
                $request,
                trans_message('notifications.preferences_update_error')
            );
        }
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
            return AdminResponse::error(
                trans_message('notifications.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $validator->errors()
            );
        }

        try {
            $preference = NotificationPreference::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'organization_id' => $request->organization_id,
                    'notification_type' => $request->notification_type,
                ],
                [
                    'quiet_hours_start' => $request->quiet_hours_start,
                    'quiet_hours_end' => $request->quiet_hours_end,
                ]
            );

            return AdminResponse::success(
                $preference,
                trans_message('notifications.quiet_hours_updated')
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'updateQuietHours',
                $e,
                $request,
                trans_message('notifications.quiet_hours_update_error')
            );
        }
    }

    private function handleUnexpectedError(
        string $action,
        \Throwable $e,
        Request $request,
        string $message
    ): JsonResponse {
        Log::error("[NotificationPreferencesController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'user_id' => $request->user()?->id,
            'organization_id' => $request->query('organization_id'),
        ]);

        return AdminResponse::error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
