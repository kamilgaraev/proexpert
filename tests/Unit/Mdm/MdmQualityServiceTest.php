<?php

declare(strict_types=1);

namespace Tests\Unit\Mdm;

use App\BusinessModules\Core\Mdm\Services\MdmNormalizationService;
use App\BusinessModules\Core\Mdm\Services\MdmQualityService;
use PHPUnit\Framework\TestCase;

final class MdmQualityServiceTest extends TestCase
{
    public function test_contractors_receive_quality_issues_for_missing_and_invalid_requisites(): void
    {
        $service = new MdmQualityService(new MdmNormalizationService());

        $result = $service->evaluate('contractor', [
            'name' => '',
            'inn' => '123',
            'kpp' => '55',
            'email' => 'bad-mail',
        ]);

        $this->assertLessThan(100, $result['score']);
        $this->assertContains('name_required', array_column($result['issues'], 'code'));
        $this->assertContains('inn_invalid', array_column($result['issues'], 'code'));
        $this->assertContains('kpp_invalid', array_column($result['issues'], 'code'));
        $this->assertContains('email_invalid', array_column($result['issues'], 'code'));
    }

    public function test_complete_material_has_high_quality_score(): void
    {
        $service = new MdmQualityService(new MdmNormalizationService());

        $result = $service->evaluate('material', [
            'name' => 'Бетон М300',
            'code' => 'M300',
            'measurement_unit_id' => 1,
            'default_price' => 4500,
        ]);

        $this->assertSame(100, $result['score']);
        $this->assertSame([], $result['issues']);
    }
}
