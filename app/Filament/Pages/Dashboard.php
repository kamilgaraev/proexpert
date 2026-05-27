<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\SystemAdmin;
use Illuminate\Support\Facades\Auth;

use function trans_message;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::dashboard();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('filament_navigation.dashboard.label');
    }

    public static function canAccess(): bool
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin && SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW);
    }
}
