<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HandoverReadinessCanonicalHashContractTest extends TestCase
{
    #[Test]
    public function provider_preserves_materialized_identity_and_publishes_canonical_identity(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/HandoverAcceptance/Reporting/Readiness/Providers/HandoverReadinessReportProvider.php',
        );

        self::assertStringContainsString('CanonicalReportSourceHashBuilder $identities', $source);
        self::assertStringContainsString('$this->identities->build(', $source);
        self::assertStringContainsString('sourceHash: $canonical', $source);
        self::assertStringContainsString('materializedSourceHash: $provisional->materializedSourceHash', $source);
    }
}
