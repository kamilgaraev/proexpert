<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Support\Reporting\OwnerSnapshotResultFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OwnerSnapshotResultFactoryTest extends TestCase
{
    #[Test]
    public function date_watermarks_are_encoded_as_safe_stable_provenance_identifiers(): void
    {
        $method = new ReflectionMethod(OwnerSnapshotResultFactory::class, 'watermarkIdentifier');

        $first = $method->invoke(null, '2026-08-09T00:45:00+00:00');
        $same = $method->invoke(null, '2026-08-09T00:45:00+00:00');
        $other = $method->invoke(null, '2026-08-09T00:46:00+00:00');

        self::assertMatchesRegularExpression('/^watermark_[a-f0-9]{32}$/', $first);
        self::assertSame($first, $same);
        self::assertNotSame($first, $other);
    }
}
