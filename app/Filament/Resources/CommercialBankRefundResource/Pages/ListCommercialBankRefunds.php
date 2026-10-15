<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommercialBankRefundResource\Pages;

use App\Filament\Resources\CommercialBankRefundResource;
use Filament\Resources\Pages\ListRecords;

class ListCommercialBankRefunds extends ListRecords
{
    protected static string $resource = CommercialBankRefundResource::class;
}
