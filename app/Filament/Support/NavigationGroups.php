<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Navigation\NavigationGroup;

use function trans_message;

final class NavigationGroups
{
    public const SORT_DASHBOARD = 10;
    public const SORT_PLATFORM = 20;
    public const SORT_ORGANIZATIONS = 30;
    public const SORT_BILLING = 40;
    public const SORT_USERS = 50;
    public const SORT_BLOG = 60;
    public const SORT_SUPPORT = 70;
    public const SORT_NOTIFICATIONS = 80;
    public const SORT_AUDIT = 90;
    public const SORT_SETTINGS = 100;

    public static function dashboard(): string
    {
        return trans_message('filament_navigation.groups.dashboard');
    }

    public static function platform(): string
    {
        return trans_message('filament_navigation.groups.platform');
    }

    public static function organizations(): string
    {
        return trans_message('filament_navigation.groups.organizations');
    }

    public static function billing(): string
    {
        return trans_message('filament_navigation.groups.billing');
    }

    public static function users(): string
    {
        return trans_message('filament_navigation.groups.users');
    }

    public static function blog(): string
    {
        return trans_message('filament_navigation.groups.blog');
    }

    public static function support(): string
    {
        return trans_message('filament_navigation.groups.support');
    }

    public static function notifications(): string
    {
        return trans_message('filament_navigation.groups.notifications');
    }

    public static function audit(): string
    {
        return trans_message('filament_navigation.groups.audit');
    }

    public static function settings(): string
    {
        return trans_message('filament_navigation.groups.settings');
    }

    /**
     * @return list<string>
     */
    public static function orderedLabels(): array
    {
        return [
            self::dashboard(),
            self::platform(),
            self::organizations(),
            self::billing(),
            self::users(),
            self::blog(),
            self::support(),
            self::notifications(),
            self::audit(),
            self::settings(),
        ];
    }

    /**
     * @return list<NavigationGroup>
     */
    public static function panelGroups(): array
    {
        return [
            NavigationGroup::make()
                ->label(self::dashboard()),
            NavigationGroup::make()
                ->label(self::platform()),
            NavigationGroup::make()
                ->label(self::organizations()),
            NavigationGroup::make()
                ->label(self::billing()),
            NavigationGroup::make()
                ->label(self::users()),
            NavigationGroup::make()
                ->label(self::blog()),
            NavigationGroup::make()
                ->label(self::support()),
            NavigationGroup::make()
                ->label(self::notifications()),
            NavigationGroup::make()
                ->label(self::audit()),
            NavigationGroup::make()
                ->label(self::settings()),
        ];
    }
}
