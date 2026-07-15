<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use DomainException;
use Illuminate\Http\Request;

final class NotificationRequestInterfaceResolver
{
    public function resolve(Request $request): NotificationInterface
    {
        $path = trim($request->path(), '/');

        return match (true) {
            $this->hasPrefix($path, 'api/v1/admin') => NotificationInterface::Admin,
            $this->hasPrefix($path, 'api/v1/landing') => NotificationInterface::Lk,
            $this->hasPrefix($path, 'api/v1/mobile') => NotificationInterface::Mobile,
            $this->hasPrefix($path, 'api/v1/customer') => NotificationInterface::Customer,
            $this->hasPrefix($path, 'api/customer') => NotificationInterface::Customer,
            $this->hasPrefix($path, 'api/notifications') => NotificationInterface::Admin,
            default => throw new DomainException('Unknown notification API contour'),
        };
    }

    private function hasPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }
}
