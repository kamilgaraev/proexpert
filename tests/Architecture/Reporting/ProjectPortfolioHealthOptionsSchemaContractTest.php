<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProjectPortfolioHealthOptionsSchemaContractTest extends TestCase
{
    #[Test]
    public function manager_options_use_the_canonical_user_name_column(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthOptionsService.php',
        );

        self::assertStringContainsString("NULLIF(TRIM(users.name), '') AS name", $source);
        self::assertStringNotContainsString('users.last_name', $source);
        self::assertStringNotContainsString('users.first_name', $source);
        self::assertStringNotContainsString('users.middle_name', $source);
    }
}
