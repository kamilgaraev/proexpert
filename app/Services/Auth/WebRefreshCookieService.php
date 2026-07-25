<?php

declare(strict_types=1);

namespace App\Services\Auth;

use DateTimeInterface;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;

final class WebRefreshCookieService
{
    public function make(string $audience, string $token, DateTimeInterface $expiresAt): Cookie
    {
        return Cookie::create(
            $this->nameFor($audience),
            $token,
            $expiresAt,
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    public function clear(string $audience): Cookie
    {
        return Cookie::create(
            $this->nameFor($audience),
            '',
            1,
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    public function tokenFromRequest(Request $request, string $audience): ?string
    {
        $token = $request->cookie($this->nameFor($audience));

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function nameFor(string $audience): string
    {
        $name = config("web_auth.cookies.{$audience}.name");

        if (! is_string($name) || ! str_starts_with($name, '__Host-')) {
            throw new LogicException('Invalid web refresh cookie configuration.');
        }

        return $name;
    }
}
