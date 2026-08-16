<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Enums;

enum MachineryAssetType: string
{
    case Machinery = 'machinery';

    public function label(): string
    {
        return trans_message("machinery_operations.asset_types.{$this->value}");
    }
}
