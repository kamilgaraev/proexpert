<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAdminResource\Pages;

use App\Filament\Resources\SystemAdminResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSystemAdmins extends ListRecords
{
    protected static string $resource = SystemAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
