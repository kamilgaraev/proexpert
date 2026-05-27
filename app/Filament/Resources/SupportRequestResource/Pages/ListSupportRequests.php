<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRequestResource\Pages;

use App\Filament\Resources\SupportRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListSupportRequests extends ListRecords
{
    protected static string $resource = SupportRequestResource::class;
}
