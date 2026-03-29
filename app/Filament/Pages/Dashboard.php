<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\SystemAdmin;
use Illuminate\Support\Facades\Auth;

class Dashboard extends \Filament\Pages\Dashboard
{
    public static function canAccess(): bool
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin
            && $user->hasSystemPermission('system_admin.dashboard.view');
    }
}
