<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Services\PurchaseRequestNumberGenerator;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use PHPUnit\Framework\TestCase;

class PurchaseRequestNumberGeneratorTest extends TestCase
{
    public function test_it_resolves_distinct_prefixes_for_site_request_types(): void
    {
        $generator = new PurchaseRequestNumberGenerator();

        self::assertSame('ЗМ', $generator->prefixForSiteRequestType(SiteRequestTypeEnum::MATERIAL_REQUEST));
        self::assertSame('ЗТ', $generator->prefixForSiteRequestType(SiteRequestTypeEnum::EQUIPMENT_REQUEST));
        self::assertSame('ЗК', $generator->prefixForSiteRequestType(SiteRequestTypeEnum::PERSONNEL_REQUEST));
        self::assertSame('ЗЗ', $generator->prefixForSiteRequestType(null));
        self::assertSame('ЗЗ', $generator->prefixForSiteRequestType(SiteRequestTypeEnum::INFO_REQUEST));
        self::assertNotSame('ЗП', $generator->prefixForSiteRequestType(SiteRequestTypeEnum::PERSONNEL_REQUEST));
    }
}
