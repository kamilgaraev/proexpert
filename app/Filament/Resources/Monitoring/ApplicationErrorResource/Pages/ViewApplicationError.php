<?php

declare(strict_types=1);

namespace App\Filament\Resources\Monitoring\ApplicationErrorResource\Pages;

use App\Filament\Resources\Monitoring\ApplicationErrorResource;
use Filament\Resources\Pages\ViewRecord;

class ViewApplicationError extends ViewRecord
{
    protected static string $resource = ApplicationErrorResource::class;
}
