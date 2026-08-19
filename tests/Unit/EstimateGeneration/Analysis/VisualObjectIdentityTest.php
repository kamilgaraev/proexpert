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
}
