<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AuthSessionRevocationService
{
    public function __construct(
        private readonly UserAuthSessionService $sessions,
        private readonly WebAuthTokenService $webTokens,
    ) {
    }

    public function revokeAllForUser(User $user, string $reason): int
    {
        $revokedSessions = DB::transaction(function () use ($user, $reason) {
            $activeSessions = UserAuthSession::query()
                ->active()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            foreach ($activeSessions as $session) {
                $this->sessions->revoke($session, $reason);
            }

            return $activeSessions;
        });

        $sessionReferences = $revokedSessions
            ->map(static fn (UserAuthSession $session): array => [
                'id' => $session->id,
                'uuid' => $session->session_uuid,
            ])
            ->all();

        DB::afterCommit(function () use ($user, $sessionReferences): void {
            foreach ($sessionReferences as $session) {
                try {
                    $this->webTokens->invalidateRefreshSession('lk', $session['uuid']);
                    $this->webTokens->invalidateRefreshSession('admin', $session['uuid']);
                } catch (\Throwable $exception) {
                    Log::warning('web_auth.refresh_state_cleanup_failed', [
                        'user_id' => $user->id,
                        'session_id' => $session['id'],
                        'exception_class' => $exception::class,
                    ]);
                }
            }
        });

        return $revokedSessions->count();
    }
}
