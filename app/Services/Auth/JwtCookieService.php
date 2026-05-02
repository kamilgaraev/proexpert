<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class JwtCookieService
{
    public function tokenFromRequest(Request $request): ?string
    {
        $token = $request->cookies->get($this->cookieName());

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function makeTokenCookie(string $token): Cookie
    {
        return cookie(
            name: $this->cookieName(),
            value: $token,
            minutes: $this->ttlMinutes(),
            path: '/',
            domain: $this->cookieDomain(),
            secure: $this->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: $this->sameSite()
        );
    }

    public function makeClearCookie(): Cookie
    {
        return cookie(
            name: $this->cookieName(),
            value: '',
            minutes: -2628000,
            path: '/',
            domain: $this->cookieDomain(),
            secure: $this->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: $this->sameSite()
        );
    }

    private function cookieName(): string
    {
        return (string) config('auth_tokens.landing_cookie.name', 'prohelper_landing_token');
    }

    private function cookieDomain(): ?string
    {
        $domain = config('auth_tokens.landing_cookie.domain');

        return is_string($domain) && $domain !== '' && strtolower($domain) !== 'null'
            ? $domain
            : null;
    }

    private function isSecure(): bool
    {
        return (bool) config('auth_tokens.landing_cookie.secure', app()->environment('production'));
    }

    private function sameSite(): string
    {
        $sameSite = strtolower((string) config('auth_tokens.landing_cookie.same_site', 'lax'));

        return in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax';
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('auth_tokens.landing_cookie.ttl_minutes', 60));
    }
}
