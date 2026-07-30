<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportCatalogGroup: string
{
    case PORTFOLIO = 'portfolio';
    case PROJECTS = 'projects';
    case FINANCE = 'finance';
    case PROCUREMENT_WAREHOUSE = 'procurement_warehouse';
    case TEAM = 'team';
    case QUALITY_SAFETY = 'quality_safety';
    case PARTNERS_CUSTOMERS = 'partners_customers';

    public static function ordered(): array
    {
        return [
            self::PORTFOLIO,
            self::PROJECTS,
            self::FINANCE,
            self::PROCUREMENT_WAREHOUSE,
            self::TEAM,
            self::QUALITY_SAFETY,
            self::PARTNERS_CUSTOMERS,
        ];
    }
}
