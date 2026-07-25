<?php

declare(strict_types=1);

namespace App\Services\Security;

final class WebOriginPolicy
{
    public function allows(?string $origin, string $audience): bool
    {
        return $this->matches($origin, $this->originsFor($audience));
    }

    public function matches(?string $origin, array $allowedOrigins): bool
    {
        $normalized = $this->normalize($origin);

        if ($normalized === null) {
            return false;
        }

        foreach ($allowedOrigins as $allowedOrigin) {
            if ($normalized === $this->normalize(is_string($allowedOrigin) ? $allowedOrigin : null)) {
                return true;
            }
        }

        return false;
    }

    public function normalize(?string $origin): ?string
    {
        if (! is_string($origin) || $origin === '') {
            return null;
        }

        $parts = parse_url($origin);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['path'], $parts['query'], $parts['fragment'])
        ) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    public function originsFor(string $audience): array
    {
        $origins = config("web_auth.origins.{$audience}", []);

        return is_array($origins) ? $origins : [];
    }
}
