<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\SystemAdmin;
use Illuminate\Support\Facades\Auth;

final class SystemAdminAccess
{
    public static function user(): ?SystemAdmin
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin ? $user : null;
    }

    public static function can(string $permission): bool
    {
        return self::user()?->hasSystemPermission($permission) ?? false;
    }

    /**
     * @param list<string> $permissions
     */
    public static function canAny(array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $permissions
     */
    public static function canAll(array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (! self::can($permission)) {
                return false;
            }
        }

        return true;
    }
}

