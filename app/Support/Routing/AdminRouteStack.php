<?php

declare(strict_types=1);

namespace App\Support\Routing;

final class AdminRouteStack
{
    /**
     * @param list<string> $extra
     * @return list<string>
     */
    public static function middleware(array $extra = []): array
    {
        return array_values(array_unique([
            'api',
            'admin.response',
            'auth:api_admin',
            'auth.jwt:api_admin',
            'organization.context',
            'authorize:admin.access',
            'interface:admin',
            ...$extra,
        ]));
    }
}
