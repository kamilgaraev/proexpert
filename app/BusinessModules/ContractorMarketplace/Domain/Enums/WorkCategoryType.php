<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Enums;

enum WorkCategoryType: string
{
    case CONSTRUCTION = 'construction';
    case ENGINEERING = 'engineering';
    case INSTALLATION = 'installation';
    case FINISHING = 'finishing';
    case DESIGN = 'design';
    case SUPERVISION = 'supervision';
    case SERVICE = 'service';
    case SUPPLY = 'supply';
}
