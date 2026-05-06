<?php

declare(strict_types=1);

namespace Tests\Unit\SiteRequests;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use PHPUnit\Framework\TestCase;

class SiteRequestTypeCompatibilityTest extends TestCase
{
    public function test_site_request_reads_legacy_material_type_as_material_request(): void
    {
        $siteRequest = new SiteRequest();
        $siteRequest->setRawAttributes(['request_type' => 'material'], true);

        $this->assertSame(SiteRequestTypeEnum::MATERIAL_REQUEST, $siteRequest->request_type);
    }

    public function test_site_request_stores_canonical_type_value(): void
    {
        $siteRequest = new SiteRequest();
        $siteRequest->request_type = 'material';

        $this->assertSame(SiteRequestTypeEnum::MATERIAL_REQUEST->value, $siteRequest->getAttributes()['request_type']);
    }
}
