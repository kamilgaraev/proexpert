<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\RoomAreaAnnotationParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoomAreaAnnotationParserTest extends TestCase
{
    #[Test]
    public function parses_decimal_room_area_and_classifies_external_spaces(): void
    {
        $parser = new RoomAreaAnnotationParser;

        self::assertSame([
            'name' => 'Кухня-столовая',
            'area_m2' => 42.7,
            'included_in_floor_area' => true,
        ], $parser->parse('Кухня-столовая 42,7'));
        self::assertSame([
            'name' => 'Веранда',
            'area_m2' => 20.8,
            'included_in_floor_area' => false,
        ], $parser->parse("Веранда\n20.8 м²"));
        self::assertSame([
            'name' => 'Спальня №2',
            'area_m2' => 17.4,
            'included_in_floor_area' => true,
        ], $parser->parse('Спальня №2 17,4'));
    }

    #[DataProvider('invalidLabels')]
    #[Test]
    public function rejects_ambiguous_or_unsafe_numeric_labels(string $label): void
    {
        self::assertNull((new RoomAreaAnnotationParser)->parse($label));
    }

    public static function invalidLabels(): array
    {
        return [
            ['Комната №2.1'],
            ['Помещение 2'],
            ['Ось 13.37'],
            ['Склад 0,1'],
            ['Зал 501,0'],
            ['<script> 12,5'],
        ];
    }
}
