<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\SecuritySessionsRequest;
use App\Services\Auth\UserAuthSessionQuery;
use App\Http\Resources\Auth\UserAuthSessionResource;
use App\Http\Resources\Auth\UserSecurityEventResource;
use App\Http\Responses\LandingResponse;
use App\Models\UserAuthSession;
use App\Services\Auth\UserAuthSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SecuritySessionController extends Controller
{
    public function __construct(private readonly UserAuthSessionService $sessions)
    {
    }

    public function index(SecuritySessionsRequest $request, UserAuthSessionQuery $query): JsonResponse
    {
        try {
            $sessions = $query->paginate(
                $request->user(),
                (string) $request->validated('group', 'all'),
                (int) $request->validated('per_page', 20),
                (int) $request->validated('page', 1),
            );

            return LandingResponse::paginated(
                UserAuthSessionResource::collection($sessions->items())->resolve($request),
                [
                    'current_page' => $sessions->currentPage(),
                    'per_page' => $sessions->perPage(),
                    'last_page' => $sessions->lastPage(),
                    'total' => $sessions->total(),
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Failed to list security sessions', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('auth.security_sessions_load_error'), 500);
        }
    }

    public function events(Request $request): JsonResponse
    {
        try {
            $events = $request->user()->securityEvents()
                ->latest()
                ->limit(100)
                ->get();

            return LandingResponse::success(UserSecurityEventResource::collection($events)->resolve($request));
        } catch (\Throwable $e) {
            Log::error('Failed to list security events', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('auth.security_events_load_error'), 500);
        }
    }

    public function destroy(Request $request, UserAuthSession $session): JsonResponse
    {
        if ((int) $session->user_id !== (int) $request->user()->id) {
            return LandingResponse::error(trans_message('auth.security_session_not_found'), 404);
        }

        $this->sessions->revoke($session, 'manual_revoke');

        return LandingResponse::success(null, trans_message('auth.security_session_revoked'));
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $current = $request->attributes->get('auth_session');
        $count = $current
            ? $this->sessions->revokeOtherSessions($request->user(), $current->session_uuid, 'manual_revoke_others')
            : 0;

        return LandingResponse::success(
            ['revoked_count' => $count],
            trans_message('auth.security_other_sessions_revoked')
        );
    }
}
