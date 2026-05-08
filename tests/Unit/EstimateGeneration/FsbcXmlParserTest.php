<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\FsbcXmlParser;
use Tests\TestCase;

class FsbcXmlParserTest extends TestCase
{
    public function test_parse_reads_machine_price_decomposition(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'fsbc-mach-') . '.xml';

        file_put_contents($filePath, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<base>
  <Resource Code="91.14.02-001" Name="Автомобили бортовые, грузоподъемность до 5 т" MeasureUnit="маш.-ч">
    <Price SalaryMach="336.43" LabourMach="1.00" PriceCostWithoutSalary="477.92" DriverCode="4-100-040" MachinistCategory="4.0" />
  </Resource>
</base>
XML);

        try {
            $prices = iterator_to_array(app(FsbcXmlParser::class)->parse($filePath), false);
        } finally {
            @unlink($filePath);
        }

        $this->assertCount(1, $prices);
        $this->assertSame('91.14.02-001', $prices[0]->code);
        $this->assertSame(814.35, $prices[0]->basePrice);
        $this->assertSame(336.43, $prices[0]->salaryMach);
        $this->assertSame(477.92, $prices[0]->priceCostWithoutSalary);
        $this->assertSame(1.0, $prices[0]->labourMach);
        $this->assertSame('4-100-040', $prices[0]->driverCode);
        $this->assertSame('4.0', $prices[0]->machinistCategory);
    }
}
