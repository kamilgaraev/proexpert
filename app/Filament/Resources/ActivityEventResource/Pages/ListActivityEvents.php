<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityEventResource\Pages;

use App\Filament\Resources\ActivityEventResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityEvents extends ListRecords
{
    protected static string $resource = ActivityEventResource::class;
}
