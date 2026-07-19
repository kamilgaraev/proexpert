<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DocumentFloorIdentityResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentFloorIdentityResolverTest extends TestCase
{
    #[Test]
    #[DataProvider('recognizedDocumentNames')]
    public function it_resolves_a_floor_only_from_an_explicit_floor_marker(string $name, string $expected): void
    {
        self::assertSame($expected, (new DocumentFloorIdentityResolver)->resolve($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function recognizedDocumentNames(): iterable
    {
        yield 'russian number before marker' => ['01-план-1-этажа.jpg', 'floor-1'];
        yield 'russian genitive ordinal' => ['План 2-го этажа.pdf', 'floor-2'];
        yield 'russian marker before number' => ['Архитектура — этаж 2.dwg', 'floor-2'];
        yield 'english marker before number' => ['floor 1 plan.png', 'floor-1'];
        yield 'english ordinal before marker' => ['1st floor layout.pdf', 'floor-1'];
        yield 'english level' => ['Level 2 - rooms.jpeg', 'floor-2'];
        yield 'roman after explicit marker' => ['План этажа II.pdf', 'floor-2'];
        yield 'roman before explicit marker' => ['Level I plan.dwg', 'floor-1'];
    }

    #[Test]
    #[DataProvider('ambiguousOrUnrelatedDocumentNames')]
    public function it_fails_closed_for_ambiguous_or_unrelated_numbers(string $name): void
    {
        self::assertNull((new DocumentFloorIdentityResolver)->resolve($name));
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousOrUnrelatedDocumentNames(): iterable
    {
        yield 'two different floors' => ['Планы 1 и 2 этажей.pdf'];
        yield 'two explicit floor markers' => ['floor 1 and floor 2.pdf'];
        yield 'russian floor range before marker' => ['Планы 1-2 этажей.pdf'];
        yield 'russian floor range after marker' => ['Этажи 1–2.pdf'];
        yield 'russian ordinal floor range' => ['Планы 1-го — 2-го этажей.pdf'];
        yield 'english floor range before marker' => ['1st-2nd floors.pdf'];
        yield 'english floor list after marker' => ['Floors 1, 2 and 3.pdf'];
        yield 'year' => ['Проект дома 2026.pdf'];
        yield 'area' => ['Дом 180 м2.pdf'];
        yield 'sheet number' => ['Лист 2 План помещений.pdf'];
        yield 'document prefix only' => ['02-план-дома.jpg'];
        yield 'unmarked roman numeral' => ['План II.pdf'];
        yield 'out of range floor' => ['floor 2026 plan.pdf'];
    }

    #[Test]
    public function it_uses_a_document_title_when_the_filename_has_no_floor_marker(): void
    {
        self::assertSame(
            'floor-2',
            (new DocumentFloorIdentityResolver)->resolve('architectural-plan.pdf', 'План 2-го этажа'),
        );
    }

    #[Test]
    public function it_fails_closed_when_filename_and_title_name_different_floors(): void
    {
        self::assertNull(
            (new DocumentFloorIdentityResolver)->resolve('floor-1.pdf', 'Level 2'),
        );
    }
}
