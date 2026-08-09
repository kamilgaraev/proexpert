<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BudgetingPortfolioCanonicalHashContractTest extends TestCase
{
    #[Test]
    public function portfolio_providers_preserve_materialized_identity_and_publish_canonical_identity(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/BudgetingPortfolioProjectionService.php',
        );

        self::assertStringContainsString('CanonicalReportSourceHashBuilder $identities', $source);
        self::assertStringContainsString('$this->identities->build(', $source);
        self::assertStringContainsString('sourceHash: $canonical', $source);
        self::assertStringContainsString('materializedSourceHash: $provisional->materializedSourceHash', $source);
        self::assertStringContainsString(
            '$snapshot->materializedSourceHash->value',
            $source,
        );

        $querySource = (string) file_get_contents(
            dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/BudgetingPortfolioQueryService.php',
        );
        self::assertStringContainsString(
            '$snapshot->materializedSourceHash->value',
            $querySource,
        );
    }
}
