<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommercialBankDocumentResource\Pages;

use App\Filament\Resources\CommercialBankDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListCommercialBankDocuments extends ListRecords
{
    protected static string $resource = CommercialBankDocumentResource::class;
}
