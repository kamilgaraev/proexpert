<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationResource\Pages;

use App\Filament\Resources\NotificationResource;
use App\Filament\Widgets\NotificationDeliveryStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListNotifications extends ListRecords
{
    protected static string $resource = NotificationResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            NotificationDeliveryStatsWidget::class,
        ];
    }
}
