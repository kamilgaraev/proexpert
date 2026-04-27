<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationAnalyticsResource\Pages;

use App\Filament\Resources\NotificationAnalyticsResource;
use App\Filament\Widgets\NotificationDeliveryStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListNotificationAnalytics extends ListRecords
{
    protected static string $resource = NotificationAnalyticsResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            NotificationDeliveryStatsWidget::class,
        ];
    }
}
