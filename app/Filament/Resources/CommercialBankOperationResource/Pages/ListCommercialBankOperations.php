<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommercialBankOperationResource\Pages;

use App\Filament\Resources\CommercialBankOperationResource;
use Filament\Resources\Pages\ListRecords;

class ListCommercialBankOperations extends ListRecords
{
    protected static string $resource = CommercialBankOperationResource::class;
}
