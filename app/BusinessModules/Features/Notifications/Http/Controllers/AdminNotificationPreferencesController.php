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

final class AdminNotificationPreferencesController extends Controller
{
    public function __construct(private readonly LandingNotificationPreferencesService $preferences) {}

    public function index(ReadNotificationPreferencesRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        try {
            return AdminResponse::success($this->preferences->read($actor, (int) $request->attributes->get('current_organization_id', 0)));
        } catch (AuthorizationException) {
            return AdminResponse::error(trans_message('notification_preferences.access_denied'), 403);
        }
    }
}
