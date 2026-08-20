<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\VisualObjectIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisualObjectIdentityTest extends TestCase
{
    #[Test]
    public function bounded_russian_and_english_room_aliases_share_identity(): void
    {
        $identity = new VisualObjectIdentity;

        self::assertSame(
            $identity->identity('kitchen_fixture', 'room.kitchen.sink', 'Kitchen sink'),
            $identity->identity('kitchen_fixture', 'room-кухня-мойка', 'Кухонная мойка'),
        );
        self::assertSame(
            $identity->identity('sanitary_fixture', 'room:bathroom:toilet', 'Toilet'),
            $identity->identity('sanitary_fixture', 'room.санузел.унитаз', 'Унитаз'),
        );
        self::assertSame('room:bathroom', $identity->roomKey('room_с_у_раковина'));
        self::assertSame('room:bathroom', $identity->roomKey('room-ванная-умывальник'));
    }

    #[Test]
    public function unknown_scope_rooms_types_and_instances_do_not_collapse(): void
    {
        $identity = new VisualObjectIdentity;

        self::assertNotSame(
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.1', 'Унитаз'),
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.2', 'Унитаз'),
        );
        foreach (['left', 'right', '01'] as $instance) {
            self::assertNotSame(
                $identity->identity('sanitary_fixture', 'room.bathroom.toilet', 'Унитаз'),
                $identity->identity('sanitary_fixture', 'room.bathroom.toilet.'.$instance, 'Унитаз'),
            );
        }
        self::assertNotSame(
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.left', 'Унитаз'),
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.right', 'Унитаз'),
        );
        self::assertNotSame(
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet', 'Унитаз'),
            $identity->identity('sanitary_fixture', 'room.guest_bathroom.toilet', 'Унитаз'),
        );
        self::assertNotSame(
            $identity->identity('sanitary_fixture', 'room.лаборатория.alpha', 'Неизвестный прибор'),
            $identity->identity('sanitary_fixture', 'room.мастерская.alpha', 'Неизвестный прибор'),
        );
        self::assertNotSame(
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet', 'Унитаз'),
            $identity->identity('sanitary_fixture', 'room.bathroom.washbasin', 'Умывальник'),
        );
    }

    #[Test]
    public function numeric_ordinals_are_canonical_without_collapsing_distinct_or_missing_instances(): void
    {
        $identity = new VisualObjectIdentity;
        $one = $identity->identity('sanitary_fixture', 'room.bathroom.toilet.1', 'Унитаз');

        foreach (['01', '001'] as $equivalentOrdinal) {
            self::assertSame(
                $one,
                $identity->identity('sanitary_fixture', 'room.bathroom.toilet.'.$equivalentOrdinal, 'Toilet'),
                $equivalentOrdinal,
            );
        }
        self::assertNotSame(
            $one,
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.2', 'Унитаз'),
        );
        self::assertNotSame(
            $one,
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet', 'Унитаз'),
        );
        self::assertNotSame(
            $one,
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.left', 'Унитаз'),
        );
        self::assertNotSame(
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.left', 'Унитаз'),
            $identity->identity('sanitary_fixture', 'room.bathroom.toilet.right', 'Унитаз'),
        );
    }

    #[Test]
    public function zero_signed_unicode_mixed_and_oversized_instances_have_bounded_distinct_identity(): void
    {
        $identity = new VisualObjectIdentity;
        $absent = $identity->identity('kitchen_fixture', 'room.kitchen.sink', 'Кухонная мойка');
        $zero = $identity->identity('kitchen_fixture', 'room.kitchen.sink.0', 'Кухонная мойка');
        $one = $identity->identity('kitchen_fixture', 'room.kitchen.sink.1', 'Кухонная мойка');

        foreach (['00', '000'] as $equivalentZero) {
            self::assertSame(
                $zero,
                $identity->identity('kitchen_fixture', 'room.kitchen.sink.'.$equivalentZero, 'Кухонная мойка'),
                $equivalentZero,
            );
        }
        self::assertNotSame($absent, $zero);
        foreach (['-1', '+1', '１', ' 1', '1 ', '1a', str_repeat('9', 129)] as $unsupported) {
            $actual = $identity->identity(
                'kitchen_fixture',
                'room.kitchen.sink.'.$unsupported,
                'Кухонная мойка',
            );

            self::assertNotSame($one, $actual, $unsupported);
            self::assertSame(
                $actual,
                $identity->identity('kitchen_fixture', 'room.kitchen.sink.'.$unsupported, 'Кухонная мойка'),
                $unsupported,
            );
            self::assertLessThanOrEqual(180, strlen($actual), $unsupported);
        }
        self::assertNotSame(
            $identity->identity('kitchen_fixture', 'room.kitchen.sink.-1', 'Кухонная мойка'),
            $identity->identity('kitchen_fixture', 'room.kitchen.sink.1', 'Кухонная мойка'),
        );
    }

    #[Test]
    public function generic_sink_uses_bounded_room_context_and_unknown_context_stays_neutral(): void
    {
        $identity = new VisualObjectIdentity;
        $bathroomAliases = [
            ['room.bathroom.sink', 'Bathroom sink'],
            ['room.санузел.раковина', 'Раковина'],
            ['room.wc.basin', 'Basin'],
            ['room.ванная.washbasin', 'Washbasin'],
            ['room.с.у.умывальник', 'Умывальник'],
        ];
        $kitchenAliases = [
            ['room.kitchen.sink', 'Kitchen sink'],
            ['room.кухня.мойка', 'Кухонная мойка'],
        ];

        foreach ($bathroomAliases as [$entityKey, $label]) {
            self::assertSame('washbasin', $identity->objectType($label, $entityKey), $entityKey);
            self::assertSame('Умывальник', $identity->canonicalLabel(
                $identity->objectType($label, $entityKey),
                $label,
            ));
        }
        foreach ($kitchenAliases as [$entityKey, $label]) {
            self::assertSame('kitchen_sink', $identity->objectType($label, $entityKey), $entityKey);
            self::assertSame('Кухонная мойка', $identity->canonicalLabel(
                $identity->objectType($label, $entityKey),
                $label,
            ));
        }
        self::assertSame('unknown', $identity->objectType('Sink', 'room.utility.sink'));
        self::assertSame('unknown', $identity->objectType('Bathroom sink', 'room.kitchen.sink'));
        self::assertSame('washbasin', $identity->objectType('Sink', 'fixture.sink', 'sanitary_fixture'));
        self::assertSame('kitchen_sink', $identity->objectType('Sink', 'fixture.sink', 'kitchen_fixture'));
        self::assertSame('unknown', $identity->objectType('Sink', 'fixture.sink', 'unknown_fixture'));
        self::assertSame('Объект на плане', $identity->canonicalLabel(
            $identity->objectType('Sink', 'room.utility.sink'),
            'Sink',
        ));
        self::assertNotSame(
            $identity->identity('sanitary_fixture', 'room.bathroom.sink', 'Bathroom sink'),
            $identity->identity('kitchen_fixture', 'room.kitchen.sink', 'Kitchen sink'),
        );
    }

    #[Test]
    public function strong_sink_context_conflicts_stay_unknown_instead_of_becoming_a_confident_type(): void
    {
        $identity = new VisualObjectIdentity;

        foreach ([
            ['Basin', 'room.kitchen.basin', 'kitchen_fixture'],
            ['Washbasin', 'room.kitchen.sink', 'kitchen_fixture'],
            ['Кухонная мойка', 'room.bathroom.sink', 'sanitary_fixture'],
            ['Умывальник', 'room.kitchen.washbasin', 'kitchen_fixture'],
            ['Sink', 'room.kitchen.sink', 'sanitary_fixture'],
        ] as [$label, $entityKey, $category]) {
            self::assertSame(
                'unknown',
                $identity->objectType($label, $entityKey, $category),
                $label.' | '.$entityKey.' | '.$category,
            );
        }

        self::assertSame('washbasin', $identity->objectType('Washbasin', 'room.bathroom.washbasin', 'sanitary_fixture'));
        self::assertSame('kitchen_sink', $identity->objectType('Kitchen sink', 'room.kitchen.sink', 'kitchen_fixture'));
        self::assertSame('Объект на плане', $identity->canonicalLabel('unknown', 'Washbasin'));
    }

    #[Test]
    public function hierarchical_scope_keeps_building_section_floor_room_object_and_instance(): void
    {
        $identity = new VisualObjectIdentity;
        $firstFloor = $identity->identity(
            'kitchen_fixture',
            'building.A.section.2.floor.1.room.kitchen.sink.01',
            'Kitchen sink',
        );
        $secondFloor = $identity->identity(
            'kitchen_fixture',
            'building.A.section.2.floor.2.room.kitchen.sink.1',
            'Kitchen sink',
        );
        $russianAlias = $identity->identity(
            'kitchen_fixture',
            'здание-А-секция-2-этаж-1-помещение-кухня-мойка-1',
            'Кухонная мойка',
        );

        self::assertSame($firstFloor, $russianAlias);
        self::assertNotSame($firstFloor, $secondFloor);
        self::assertStringContainsString('building:a', $firstFloor);
        self::assertStringContainsString('section:2', $firstFloor);
        self::assertStringContainsString('floor:1', $firstFloor);
        self::assertStringContainsString('room:kitchen', $firstFloor);
    }

    #[Test]
    public function unknown_prefix_is_bounded_deterministic_and_does_not_erase_known_scope(): void
    {
        $identity = new VisualObjectIdentity;
        $first = $identity->identity('sanitary_fixture', 'zone.alpha.floor.1.room.bathroom.toilet', 'Унитаз');
        $replay = $identity->identity('sanitary_fixture', 'zone_alpha_floor_1_room_bathroom_toilet', 'Toilet');
        $other = $identity->identity('sanitary_fixture', 'zone.beta.floor.1.room.bathroom.toilet', 'Унитаз');

        self::assertSame($first, $replay);
        self::assertNotSame($first, $other);
        self::assertStringContainsString('floor:1', $first);
        self::assertStringContainsString('room:bathroom', $first);
        self::assertLessThanOrEqual(240, strlen($first));
    }

    #[Test]
    public function object_aliases_match_only_bounded_tokens_and_consistent_context(): void
    {
        $identity = new VisualObjectIdentity;

        foreach ([
            ['Плита', 'room.kitchen.stove'],
            ['Cooktop', 'room.kitchen.cooktop'],
            ['Stove', 'room.kitchen.stove'],
        ] as [$label, $entityKey]) {
            self::assertSame('stove', $identity->objectType($label, $entityKey, 'kitchen_fixture'), $label);
        }
        foreach ([
            ['Плитка', 'room.kitchen.tile'],
            ['Плиточный фартук', 'room.kitchen.tile'],
            ['Плита перекрытия', 'floor.1.slab'],
            ['Плиты перекрытия', 'floor.1.slabs'],
            ['Столешница', 'room.kitchen.worktop'],
            ['Столовая зона', 'room.dining.zone'],
            ['Ванная комната', 'room.bathroom.zone'],
            ['Toiletry cabinet', 'room.bathroom.cabinet'],
            ['Stovepipe', 'building.chimney'],
            ['Условное обозначение плиты перекрытия', 'floor.1.symbol'],
        ] as [$label, $entityKey]) {
            self::assertSame('unknown', $identity->objectType($label, $entityKey, 'unknown_fixture'), $label);
        }
        $conflict = $identity->classification('Плита', 'room.bathroom.toilet', 'sanitary_fixture');
        self::assertSame('unknown', $conflict->objectType);
        self::assertSame('object_type_conflicted', $conflict->limitationCode);
    }
}
