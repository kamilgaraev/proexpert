<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationSubscriptionResource\Pages;

use App\Filament\Resources\OrganizationSubscriptionResource;
use Filament\Resources\Pages\ListRecords;

class ListOrganizationSubscriptions extends ListRecords
{
    protected static string $resource = OrganizationSubscriptionResource::class;
}
