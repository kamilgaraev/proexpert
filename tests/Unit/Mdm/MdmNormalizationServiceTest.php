<?php

declare(strict_types=1);

namespace Tests\Unit\Mdm;

use App\BusinessModules\Core\Mdm\Services\MdmNormalizationService;
use PHPUnit\Framework\TestCase;

final class MdmNormalizationServiceTest extends TestCase
{
    public function test_requisites_and_contacts_are_normalized(): void
    {
        $service = new MdmNormalizationService();

        $normalized = $service->normalizeRecord('contractor', [
            'name' => '  ООО  "Строй-Монтаж" ',
            'inn' => ' 77-01 000001 ',
            'kpp' => ' 7701 01001 ',
            'phone' => '8 (999) 123-45-67',
            'email' => ' Info@Example.Test ',
        ]);

        $this->assertSame('ооо "строй-монтаж"', $normalized['name']);
        $this->assertSame('7701000001', $normalized['inn']);
        $this->assertSame('770101001', $normalized['kpp']);
        $this->assertSame('79991234567', $normalized['phone']);
        $this->assertSame('info@example.test', $normalized['email']);
        $this->assertSame('contractor:7701000001:770101001', $normalized['normalized_key']);
    }

    public function test_material_key_prefers_code_and_unit(): void
    {
        $service = new MdmNormalizationService();

        $normalized = $service->normalizeRecord('material', [
            'name' => ' Бетон М300 ',
            'code' => ' M-300 ',
            'measurement_unit_id' => 5,
        ]);

        $this->assertSame('бетон м300', $normalized['name']);
        $this->assertSame('m-300', $normalized['code']);
        $this->assertSame('material:m-300:5', $normalized['normalized_key']);
    }
}
