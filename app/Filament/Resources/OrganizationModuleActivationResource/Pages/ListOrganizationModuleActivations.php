<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationModuleActivationResource\Pages;

use App\Filament\Resources\OrganizationModuleActivationResource;
use Filament\Resources\Pages\ListRecords;

class ListOrganizationModuleActivations extends ListRecords
{
    protected static string $resource = OrganizationModuleActivationResource::class;
}
