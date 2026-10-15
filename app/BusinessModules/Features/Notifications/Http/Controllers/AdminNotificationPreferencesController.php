<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Services\LandingNotificationPreferencesService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReadNotificationPreferencesRequest;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AdminNotificationPreferencesController extends Controller
{
    public function __construct(private readonly LandingNotificationPreferencesService $preferences) {}

    public function index(ReadNotificationPreferencesRequest $request): JsonResponse
    {
        $actor = null;
        $organizationId = (int) $request->attributes->get('current_organization_id', 0);
        try {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthorizationException;
            }

            return AdminResponse::success($this->preferences->read($actor, $organizationId));
        } catch (AuthorizationException) {
            Log::error('Admin notification preferences access denied', [
                'user_id' => $actor instanceof User ? $actor->id : null, 'organization_id' => $organizationId,
            ]);
            return AdminResponse::error(trans_message('notification_preferences.access_denied'), 403);
        } catch (Throwable $exception) {
            Log::error('Admin notification preferences load failed', [
                'user_id' => $actor instanceof User ? $actor->id : null, 'organization_id' => $organizationId,
                'exception_class' => $exception::class,
            ]);
            return AdminResponse::error(trans_message('notification_preferences.load_error'), 500);
        }
    }
}
