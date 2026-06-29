<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\QuantityStatementLineParser;
use PHPUnit\Framework\TestCase;

final class QuantityStatementLineParserTest extends TestCase
{
    public function test_parses_earthwork_row_from_work_volume_statement(): void
    {
        $row = (new QuantityStatementLineParser())->parse('Обратная засыпка пазух м3 42', 'work_volume_statement');

        self::assertIsArray($row);
        self::assertSame('Обратная засыпка пазух', $row['name']);
        self::assertSame('м3', $row['unit']);
        self::assertSame(42.0, $row['quantity']);
        self::assertSame('earth.backfill', $row['quantity_key']);
        self::assertSame('earthworks', $row['scope_type']);
        self::assertSame('work_volume_statement', $row['source']);
        self::assertTrue($row['mapped']);
        self::assertFalse($row['review_required']);
    }

    public function test_parses_finishing_row_with_quantity_before_unit(): void
    {
        $row = (new QuantityStatementLineParser())->parse('Окраска стен 180 м2', 'work_volume_statement');

        self::assertIsArray($row);
        self::assertSame('finish.paint', $row['quantity_key']);
        self::assertSame('finishing', $row['scope_type']);
        self::assertSame(180.0, $row['quantity']);
    }

    public function test_parses_equipment_specification_row(): void
    {
        $row = (new QuantityStatementLineParser())->parse('1 Светильник светодиодный шт 42');

        self::assertIsArray($row);
        self::assertSame('warehouse.lighting', $row['quantity_key']);
        self::assertSame('electrical', $row['scope_type']);
        self::assertSame('specification', $row['source']);
    }

    public function test_maps_structural_work_volume_rows(): void
    {
        $parser = new QuantityStatementLineParser();

        $cases = [
            ['Бетонирование фундаментов м3 12,5', 'foundation.concrete', 'foundation', 12.5],
            ['Армирование плиты фундамента т 1,8', 'foundation.rebar', 'foundation', 1.8],
            ['Устройство гидроизоляции фундаментов м2 120', 'foundation.waterproofing', 'foundation', 120.0],
            ['Устройство опалубки фундаментов м2 80', 'foundation.formwork', 'foundation', 80.0],
            ['Кладка наружных стен м3 38', 'walls.external_volume', 'walls', 38.0],
            ['Утепление кровли м2 194', 'roof.area', 'roof', 194.0],
            ['Бетонирование плиты пола м3 24', 'warehouse.floor_concrete', 'slabs', 24.0],
        ];

        foreach ($cases as [$line, $quantityKey, $scopeType, $quantity]) {
            $row = $parser->parse($line, 'work_volume_statement');

            self::assertIsArray($row, $line);
            self::assertSame($quantityKey, $row['quantity_key'], $line);
            self::assertSame($scopeType, $row['scope_type'], $line);
            self::assertSame($quantity, $row['quantity'], $line);
            self::assertSame('work_volume_statement', $row['source'], $line);
            self::assertTrue($row['mapped'], $line);
            self::assertFalse($row['review_required'], $line);
        }
    }

    public function test_outer_wall_finishing_rows_are_not_structural_wall_volume(): void
    {
        $parser = new QuantityStatementLineParser();

        $paint = $parser->parse('Окраска наружных стен м2 180', 'work_volume_statement');
        $plaster = $parser->parse('Штукатурка наружных стен м2 180', 'work_volume_statement');

        self::assertIsArray($paint);
        self::assertSame('finish.paint', $paint['quantity_key']);
        self::assertSame('finishing', $paint['scope_type']);

        self::assertIsArray($plaster);
        self::assertSame('rough.walls', $plaster['quantity_key']);
        self::assertSame('finishing', $plaster['scope_type']);
    }

    public function test_maps_baseboard_finishing_rows(): void
    {
        $row = (new QuantityStatementLineParser())->parse('Монтаж плинтуса ПВХ м 77', 'work_volume_statement');

        self::assertIsArray($row);
        self::assertSame('finish.baseboard', $row['quantity_key']);
        self::assertSame('finishing', $row['scope_type']);
        self::assertSame('м', $row['unit']);
        self::assertSame(77.0, $row['quantity']);
        self::assertTrue($row['mapped']);
        self::assertFalse($row['review_required']);
    }

    public function test_unknown_quantity_row_is_not_mapped_to_takeoff(): void
    {
        $row = (new QuantityStatementLineParser())->parse('Авторский надзор компл 1', 'work_volume_statement');

        self::assertIsArray($row);
        self::assertNull($row['quantity_key']);
        self::assertSame('unknown', $row['scope_type']);
        self::assertFalse($row['mapped']);
        self::assertTrue($row['review_required']);
    }

    public function test_header_row_is_ignored(): void
    {
        self::assertNull((new QuantityStatementLineParser())->parse('Поз. Наименование Ед. Количество'));
    }
}
