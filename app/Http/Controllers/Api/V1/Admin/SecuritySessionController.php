<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\SecurityEventIndexRequest;
use App\Http\Resources\Auth\UserAuthSessionResource;
use App\Http\Resources\Auth\UserSecurityEventResource;
use App\Http\Responses\AdminResponse;
use App\Models\UserAuthSession;
use App\Services\Auth\UserAuthSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class SecuritySessionController extends Controller
{
    public function __construct(private readonly UserAuthSessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                UserAuthSessionResource::collection($request->user()->authSessions()->latest('last_seen_at')->get())
                    ->resolve($request)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to list admin security sessions', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('auth.security_sessions_load_error'), 500);
        }
    }

    public function events(SecurityEventIndexRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $events = $this->sessions->paginateSecurityEvents(
                $request->user(),
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 20),
            );

            return AdminResponse::paginated(
                UserSecurityEventResource::collection($events->getCollection())->resolve($request),
                $this->paginationMeta($events),
            );
        } catch (\Throwable $e) {
            Log::error('Failed to list admin security events', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('auth.security_events_load_error'), 500);
        }
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    public function destroy(Request $request, UserAuthSession $session): JsonResponse
    {
        if ((int) $session->user_id !== (int) $request->user()->id) {
            return AdminResponse::error(trans_message('auth.security_session_not_found'), 404);
        }

        $current = $request->attributes->get('auth_session');

        if ($current && (int) $current->id === (int) $session->id) {
            return AdminResponse::error(trans_message('auth.security_current_session_revoke_forbidden'), 422);
        }

        $this->sessions->revoke($session, 'manual_revoke');

        return AdminResponse::success(null, trans_message('auth.security_session_revoked'));
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $current = $request->attributes->get('auth_session');
        $count = $current
            ? $this->sessions->revokeOtherSessions($request->user(), $current->session_uuid, 'manual_revoke_others')
            : 0;

        return AdminResponse::success(
            ['revoked_count' => $count],
            trans_message('auth.security_other_sessions_revoked')
        );
    }
}
