<?php

namespace Tests\Unit;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowMapper;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Tests\TestCase;

class ImportRowMapperTest extends TestCase
{
    public function testParseItemAttributesWithOverheadAndProfit()
    {
        $mapper = new ImportRowMapper();
        
        $text = "Врезка в существующие сети\n(шт)\nИНДЕКС К ПОЗИЦИИ(справочно):\n01 Перевод цен на 2 квартал 2018 года СМР=7,83\nНР (28,38 руб.): 130% от ФОТ\nСП (19,43 руб.): 89% от ФОТ";
        
        // Use reflection to access protected method parseItemAttributes
        $reflection = new \ReflectionClass(ImportRowMapper::class);
        $method = $reflection->getMethod('parseItemAttributes');
        $method->setAccessible(true);
        
        $attributes = $method->invoke($mapper, $text);
        
        $this->assertEquals(28.38, $attributes['overhead_amount'], 'Overhead Amount mismatch');
        $this->assertEquals(130.0, $attributes['overhead_rate'], 'Overhead Rate mismatch');
        $this->assertEquals(19.43, $attributes['profit_amount'], 'Profit Amount mismatch');
        $this->assertEquals(89.0, $attributes['profit_rate'], 'Profit Rate mismatch');
    }

    public function testParseItemAttributesWithSupravochnoCollision()
    {
        $mapper = new ImportRowMapper();
        
        // Text where "справочно" mimics "СП" if logic is weak
        $text = "Тест (справочно): 89% прочее"; 
        
        $reflection = new \ReflectionClass(ImportRowMapper::class);
        $method = $reflection->getMethod('parseItemAttributes');
        $method->setAccessible(true);
        
        $attributes = $method->invoke($mapper, $text);
        
        $this->assertArrayNotHasKey('profit_rate', $attributes, 'Should not detect profit rate from supravochno');
        $this->assertArrayNotHasKey('profit_amount', $attributes, 'Should not detect profit amount from supravochno');
    }

    public function testParseMultiLineValue()
    {
        $mapper = new ImportRowMapper();
        
        $value = "100.00\n20.00\n30.00\n5.00"; // Всего, ЗП, ЭМ, ЗПМ
        $result = $mapper->parseMultiLineValue($value);
        
        $this->assertEquals(100.00, $result['total']);
        $this->assertEquals(20.00, $result['labor']);
        $this->assertEquals(30.00, $result['machinery']);
        $this->assertEquals(5.00, $result['machinery_labor']);
        // Materials = 100 - 20 - 30 = 50
        $this->assertEquals(50.00, $result['materials']);
    }

    public function testParseMultiLineValueWithMaterials()
    {
        $mapper = new ImportRowMapper();
        
        $value = "150.00\n30.00\n40.00\n10.00\n80.00"; // Всего, ЗП, ЭМ, ЗПМ, Материалы
        $result = $mapper->parseMultiLineValue($value);
        
        $this->assertEquals(150.00, $result['total']);
        $this->assertEquals(30.00, $result['labor']);
        $this->assertEquals(40.00, $result['machinery']);
        $this->assertEquals(10.00, $result['machinery_labor']);
        $this->assertEquals(80.00, $result['materials']);
    }
}
