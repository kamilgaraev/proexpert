<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Services\CustomerActorSideResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CustomerActorSideResolverTest extends TestCase
{
    #[Test]
    public function actor_side_is_fixed_from_event_time_membership(): void
    {
        $resolver = new CustomerActorSideResolver();

        self::assertSame(
            CustomerActorSide::CUSTOMER,
            $resolver->resolve(10, 20, [20]),
        );
        self::assertSame(
            CustomerActorSide::DELIVERY_TEAM,
            $resolver->resolve(10, 20, [10]),
        );
        self::assertSame(
            CustomerActorSide::UNKNOWN,
            $resolver->resolve(10, 20, [10, 20]),
        );
        self::assertSame(
            CustomerActorSide::UNKNOWN,
            $resolver->resolve(10, null, [10]),
        );
    }
}
