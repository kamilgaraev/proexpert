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
