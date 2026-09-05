<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Features\Notifications\Services\LandingNotificationPreferencesService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\NotificationPreferencesRequest;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class NotificationPreferencesController extends Controller
{
    public function __construct(private readonly LandingNotificationPreferencesService $preferences) {}

    public function index(NotificationPreferencesRequest $request): JsonResponse
    {
        try {
            return LandingResponse::success($this->preferences->read($this->actor($request), $this->organizationId($request)));
        } catch (AuthorizationException) {
            return LandingResponse::error(trans_message('notification_preferences.access_denied'), 403);
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'load_error');
        }
    }

    public function update(NotificationPreferencesRequest $request): JsonResponse
    {
        try {
            $this->preferences->update(
                $this->actor($request),
                $this->organizationId($request),
                (string) $request->validated('notification_type'),
                $request->validated('enabled_channels'),
            );

            return LandingResponse::success(null, trans_message('notification_preferences.saved'));
        } catch (AuthorizationException) {
            return LandingResponse::error(trans_message('notification_preferences.access_denied'), 403);
        } catch (ValidationException $exception) {
            return LandingResponse::error(trans_message('notifications.validation_error'), 422, $exception->errors());
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'save_error');
        }
    }

    private function actor(NotificationPreferencesRequest $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthorizationException();
        }

        return $user;
    }

    private function organizationId(NotificationPreferencesRequest $request): int
    {
        $organizationId = (int) $request->attributes->get('current_organization_id', 0);

        if ($organizationId <= 0) {
            throw new AuthorizationException();
        }

        return $organizationId;
    }

    private function failure(NotificationPreferencesRequest $request, Throwable $exception, string $message): JsonResponse
    {
        Log::error('Landing notification preferences failed', [
            'exception' => $exception,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
        ]);

        return LandingResponse::error(trans_message('notification_preferences.' . $message), 500);
    }
}
