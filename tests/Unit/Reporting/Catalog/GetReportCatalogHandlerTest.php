<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\GetReportCatalogHandler;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use PHPUnit\Framework\TestCase;

final class GetReportCatalogHandlerTest extends TestCase
{
    public function test_handler_is_bound_to_the_catalog_action_contract(): void
    {
        self::assertContains(GetReportCatalogAction::class, class_implements(GetReportCatalogHandler::class));
    }
}
