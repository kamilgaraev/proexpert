<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Services\NotificationTargetResolver;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationTargetResolverTest extends TestCase
{
    public function test_explicit_interfaces_take_precedence_and_are_unique(): void
    {
        $resolver = new NotificationTargetResolver;

        self::assertSame(
            ['admin', 'lk'],
            array_map(
                static fn (NotificationInterface $interface): string => $interface->value,
                $resolver->resolve(['admin', 'lk', 'admin'], ['interface' => 'mobile'])
            )
        );
    }

    public function test_legacy_interface_is_used_when_explicit_interfaces_are_absent(): void
    {
        $resolver = new NotificationTargetResolver;

        self::assertSame(
            ['customer'],
            array_map(
                static fn (NotificationInterface $interface): string => $interface->value,
                $resolver->resolve([], ['interface' => 'customer'])
            )
        );
    }

    #[DataProvider('invalidTargets')]
    public function test_empty_unknown_or_invalid_targets_are_rejected(array $interfaces, array $data): void
    {
        $this->expectException(DomainException::class);

        (new NotificationTargetResolver)->resolve($interfaces, $data);
    }

    public static function invalidTargets(): array
    {
        return [
            'empty' => [[], []],
            'unknown explicit target' => [['desktop'], ['interface' => 'admin']],
            'invalid explicit target' => [[42], ['interface' => 'admin']],
            'unknown legacy target' => [[], ['interface' => 'desktop']],
            'invalid legacy target' => [[], ['interface' => ['admin']]],
        ];
    }
}
