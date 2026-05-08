<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\LaborPriceSpreadsheetParser;
use Tests\TestCase;

class LaborPriceSpreadsheetParserTest extends TestCase
{
    public function test_parse_reads_worker_and_machinist_prices_from_csv(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'labor-prices-') . '.csv';
        file_put_contents($filePath, implode("\n", [
            'Код,Наименование,Ед. изм.,Цена',
            '2-100-05,Рабочий 5 разряда,чел.-ч,37.80',
            '4-100-040,ОТм(ЗТм) Средний разряд машинистов 4,чел.-ч,336.43',
        ]));

        try {
            $prices = iterator_to_array(app(LaborPriceSpreadsheetParser::class)->parse($filePath), false);
        } finally {
            @unlink($filePath);
        }

        $this->assertCount(2, $prices);
        $this->assertSame('2-100-05', $prices[0]->code);
        $this->assertSame(37.8, $prices[0]->basePrice);
        $this->assertSame(EstimateResourceType::LABOR->value, $prices[0]->resourceType);
        $this->assertSame('4-100-040', $prices[1]->code);
        $this->assertSame(336.43, $prices[1]->basePrice);
        $this->assertSame(EstimateResourceType::MACHINE_LABOR->value, $prices[1]->resourceType);
    }
}
