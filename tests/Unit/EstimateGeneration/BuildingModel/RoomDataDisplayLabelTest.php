<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoomDataDisplayLabelTest extends TestCase
{
    #[Test]
    public function ordinary_architectural_punctuation_is_preserved_exactly(): void
    {
        $room = new RoomData('room-1', 'Санузел (1 этаж), № 2.1', null, [11], 0.9, 'confirmed');

        self::assertSame('Санузел (1 этаж), № 2.1', $room->name);
    }

    #[Test]
    #[DataProvider('unsafeLabels')]
    public function control_characters_and_html_like_brackets_are_rejected(string $label): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoomData('room-1', $label, null, [11], 0.9, 'confirmed');
    }

    public static function unsafeLabels(): array
    {
        return [
            'control' => ["Кухня\nэтаж"],
            'html' => ['<script>Комната</script>'],
        ];
    }
}
