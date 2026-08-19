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
}
