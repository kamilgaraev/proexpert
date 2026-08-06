<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeClaimSourceInstant;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChangeClaimSourceInstantTest extends TestCase
{
    public function test_business_date_is_preserved_while_the_instant_is_normalized_to_utc(): void
    {
        $source = new DateTimeImmutable('2026-08-07T00:30:00.123456+03:00');

        $instant = ChangeClaimSourceInstant::from($source);

        self::assertSame('2026-08-07', $instant->effectiveOn);
        self::assertSame('2026-08-06T21:30:00+00:00', $instant->occurredAt->format(DATE_ATOM));
        self::assertSame('2026-08-07T00:30:00+03:00', $source->format(DATE_ATOM));
    }
}
