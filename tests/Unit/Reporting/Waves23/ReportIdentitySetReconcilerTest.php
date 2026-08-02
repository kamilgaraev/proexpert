<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Support\ReportIdentitySetReconciler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportIdentitySetReconcilerTest extends TestCase
{
    #[Test]
    public function missing_extra_and_duplicate_scoped_identities_are_independent_gaps(): void
    {
        $gaps = ReportIdentitySetReconciler::gapCount(
            ['assignment:1:employee:7:site:10', 'assignment:2:employee:8:site:11'],
            [
                'assignment:1:employee:7:site:10',
                'assignment:1:employee:7:site:10',
                'assignment:3:employee:9:site:12',
            ],
        );

        self::assertSame(3, $gaps);
    }
}
