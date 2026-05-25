<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use Tests\TestCase;

final class SiteRequestLegacyEnumMappingTest extends TestCase
{
    public function test_legacy_equipment_value_crane_is_mapped(): void
    {
        $this->assertSame(
            EquipmentTypeEnum::MOBILE_CRANE,
            EquipmentTypeEnum::fromLegacyValue('crane')
        );

        $this->assertSame(
            EquipmentTypeEnum::MOBILE_CRANE,
            EquipmentTypeEnum::fromLegacyValue('mobile_crane')
        );
    }

    public function test_legacy_contract_work_type_construction_is_mapped(): void
    {
        $this->assertSame(
            ContractWorkTypeCategoryEnum::GENERAL_CONSTRUCTION,
            ContractWorkTypeCategoryEnum::fromLegacyValue('construction')
        );

        $this->assertSame(
            ContractWorkTypeCategoryEnum::GENERAL_CONSTRUCTION,
            ContractWorkTypeCategoryEnum::fromLegacyValue('general_construction')
        );
    }
}
