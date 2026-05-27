<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRequestResource\Pages;

use App\Filament\Resources\SupportRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupportRequest extends ViewRecord
{
    protected static string $resource = SupportRequestResource::class;

    protected function getHeaderActions(): array
    {
        return SupportRequestResource::supportRecordActions();
    }
}
