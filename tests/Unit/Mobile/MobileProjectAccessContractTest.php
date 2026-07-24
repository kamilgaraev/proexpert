<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use PHPUnit\Framework\TestCase;

final class MobileProjectAccessContractTest extends TestCase
{
    public function test_mobile_project_endpoint_uses_shared_project_access_scope(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Http/Controllers/Api/V1/Mobile/ProjectController.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('UserProjectAccessService', $source);
        self::assertStringContainsString('queryAccessibleProjects', $source);
    }
}
