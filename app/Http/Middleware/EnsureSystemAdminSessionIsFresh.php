<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SystemAdmin;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureSystemAdminSessionIsFresh
{
    public const SESSION_GENERATION_KEY = 'system_admin_session_generation';

    public const SESSION_ROTATED_AT_KEY = 'system_admin_session_rotated_at';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Filament::auth();

        if (! $guard instanceof SessionGuard || ! $guard->check()) {
            return $next($request);
        }

        $user = $guard->user();

        if (! $user instanceof SystemAdmin) {
            return $next($request);
        }

        if ($guard->viaRemember()) {
            return $this->rejectRememberCookieLogin($request, $guard, $user);
        }

        if (! $this->sessionMatchesCurrentGeneration($request)) {
            $this->refreshStaleSession($request);
        }

        $this->rotateSessionIdWhenNeeded($request);

        return $next($request);
    }

    private function rejectRememberCookieLogin(Request $request, SessionGuard $guard, SystemAdmin $systemAdmin): RedirectResponse
    {
        $guard->logout();

        $systemAdmin->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(Filament::getLoginUrl());
    }

    private function refreshStaleSession(Request $request): void
    {
        $request->session()->migrate(true);
        $request->session()->put(self::SESSION_GENERATION_KEY, $this->currentSessionGeneration());
        $request->session()->put(self::SESSION_ROTATED_AT_KEY, now()->getTimestamp());
    }

    private function sessionMatchesCurrentGeneration(Request $request): bool
    {
        return $request->session()->get(self::SESSION_GENERATION_KEY) === $this->currentSessionGeneration();
    }

    public static function currentSessionGeneration(): string
    {
        return (string) config('system_admin_security.session_generation', '2026-05-27-session-hardening');
    }

    private function rotateSessionIdWhenNeeded(Request $request): void
    {
        $rotationMinutes = max(1, (int) config('system_admin_security.session_rotation_minutes', 15));
        $rotatedAt = $request->session()->get(self::SESSION_ROTATED_AT_KEY);

        if (is_int($rotatedAt) && now()->getTimestamp() - $rotatedAt < $rotationMinutes * 60) {
            return;
        }

        $request->session()->migrate(true);
        $request->session()->put(self::SESSION_ROTATED_AT_KEY, now()->getTimestamp());
    }
}
