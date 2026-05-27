<?php

declare(strict_types=1);

namespace App\Filament\Resources\Monitoring\ApplicationErrorResource\Pages;

use App\Filament\Resources\Monitoring\ApplicationErrorResource;
use Filament\Resources\Pages\ListRecords;

class ListApplicationErrors extends ListRecords
{
    protected static string $resource = ApplicationErrorResource::class;
}
